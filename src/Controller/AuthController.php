<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{
    public function __construct(private readonly Address $mailerFromAddress)
    {
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $session = $request->getSession();
        $captchaQuestion = (string) $session->get('register_captcha_question', '');
        $captchaExpectedAnswer = (string) $session->get('register_captcha_expected_answer', '');

        if ($captchaQuestion === '' || $captchaExpectedAnswer === '' || !$request->isMethod('POST')) {
            $left = random_int(1, 9);
            $right = random_int(1, 9);
            $captchaQuestion = sprintf('Captcha: ¿Cuánto es %d + %d?', $left, $right);
            $captchaExpectedAnswer = (string) ($left + $right);

            $session->set('register_captcha_question', $captchaQuestion);
            $session->set('register_captcha_expected_answer', $captchaExpectedAnswer);
        }

        $form = $this->createForm(RegistrationFormType::class, $user, [
            'captcha_question' => $captchaQuestion,
            'captcha_expected_answer' => $captchaExpectedAnswer,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->getData();
            $verificationCode = (string) random_int(100000, 999999);
            $user
                ->setPassword($passwordHasher->hashPassword($user, $plainPassword))
                ->setIsVerified(false)
                ->setIsApproved(false)
                ->setEmailVerificationCode($verificationCode)
                ->setEmailVerificationExpiresAt((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 minutes'))
                ->setRoles(['ROLE_USER']);

            $entityManager->persist($user);
            $entityManager->flush();

            $session->remove('register_captcha_question');
            $session->remove('register_captcha_expected_answer');

            $this->sendVerificationEmail($mailer, $user, $verificationCode);
            $this->addFlash('success', 'Registro completado. Revisa tu correo y valida el código.');

            return $this->redirectToRoute('app_verify_code');
        }

        return $this->render('auth/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/auth/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Handled by firewall logout.');
    }

    #[Route('/verify/code', name: 'app_verify_code', methods: ['GET', 'POST'])]
    public function verifyCode(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        #[Autowire(service: 'limiter.verify_code')] RateLimiterFactory $verifyCodeLimiter,
    ): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('verify_code', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Token inválido. Intenta de nuevo.');

                return $this->redirectToRoute('app_verify_code');
            }

            $email = (string) $request->request->get('email');
            $code = (string) $request->request->get('code');
            $limiterKey = mb_strtolower(trim($email)).'|'.(string) $request->getClientIp();
            $limit = $verifyCodeLimiter->create($limiterKey)->consume(1);

            if (!$limit->isAccepted()) {
                $retryAt = $limit->getRetryAfter();
                $seconds = max(1, $retryAt->getTimestamp() - time());
                $this->addFlash('error', sprintf('Demasiados intentos. Espera %d segundos e intenta de nuevo.', $seconds));

                return $this->redirectToRoute('app_verify_code');
            }

            $user = $userRepository->findByEmail($email);
            if (!$user instanceof User || $user->getEmailVerificationCode() !== trim($code)) {
                $this->addFlash('error', 'Correo o código inválido.');

                return $this->redirectToRoute('app_verify_code');
            }

            $expiresAt = $user->getEmailVerificationExpiresAt();
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (!$expiresAt || $expiresAt < $now) {
                $this->addFlash('error', 'El código expiró. Solicita reenvío de código para continuar.');

                return $this->redirectToRoute('app_verify_code');
            }

            if ($user->isVerified()) {
                $this->addFlash('success', 'Tu correo ya está verificado.');

                return $this->redirectToRoute('app_login');
            }

            $user
                ->setIsVerified(true)
                ->setEmailVerificationCode(null)
                ->setEmailVerificationExpiresAt(null);

            $entityManager->flush();

            $this->addFlash('success', 'Correo verificado. Falta aprobación del admin para participar.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/verify_code.html.twig');
    }

    #[Route('/verify/resend', name: 'app_verify_resend', methods: ['POST'])]
    public function resendCode(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        #[Autowire(service: 'limiter.verify_resend')] RateLimiterFactory $verifyResendLimiter,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('verify_resend', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido. Intenta de nuevo.');

            return $this->redirectToRoute('app_verify_code');
        }

        $email = (string) $request->request->get('email');
        $normalizedEmail = mb_strtolower(trim($email));
        $limiterKey = $normalizedEmail.'|'.(string) $request->getClientIp();
        $limit = $verifyResendLimiter->create($limiterKey)->consume(1);

        if (!$limit->isAccepted()) {
            $retryAt = $limit->getRetryAfter();
            $seconds = max(1, $retryAt->getTimestamp() - time());
            $this->addFlash('error', sprintf('Espera %d segundos antes de pedir otro código.', $seconds));

            return $this->redirectToRoute('app_verify_code');
        }

        $user = $userRepository->findByEmail($normalizedEmail);
        if (!$user instanceof User) {
            $this->addFlash('error', 'No existe una cuenta con ese correo.');

            return $this->redirectToRoute('app_verify_code');
        }

        if ($user->isVerified()) {
            $this->addFlash('success', 'Tu correo ya está verificado. Inicia sesión.');

            return $this->redirectToRoute('app_login');
        }

        $verificationCode = (string) random_int(100000, 999999);
        $user
            ->setEmailVerificationCode($verificationCode)
            ->setEmailVerificationExpiresAt((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 minutes'));

        $entityManager->flush();

        $this->sendVerificationEmail($mailer, $user, $verificationCode);
        $this->addFlash('success', 'Te enviamos un nuevo código de verificación.');

        return $this->redirectToRoute('app_verify_code');
    }

    private function sendVerificationEmail(MailerInterface $mailer, User $user, string $verificationCode): void
    {
        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to($user->getEmail())
            ->subject('Tu código de verificación - Quiniela 2026')
            ->htmlTemplate('emails/verify_email.html.twig')
            ->context([
                'user' => $user,
                'verificationCode' => $verificationCode,
            ]);

        $mailer->send($email);
    }
}

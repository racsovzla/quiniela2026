<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RequestPasswordResetFormType;
use App\Form\ResetPasswordFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PasswordResetController extends AbstractController
{
    private const RESET_TOKEN_TTL_MINUTES = 60;

    public function __construct(
        private readonly Address $mailerFromAddress,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/reset-password', name: 'app_request_password_reset', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        #[Autowire(service: 'limiter.password_reset_request')] RateLimiterFactory $passwordResetLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(RequestPasswordResetFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim((string) $form->get('email')->getData()));
            $limiterKey = $email.'|'.(string) $request->getClientIp();
            $limit = $passwordResetLimiter->create($limiterKey)->consume(1);

            if (!$limit->isAccepted()) {
                $retryAt = $limit->getRetryAfter();
                $seconds = max(1, $retryAt->getTimestamp() - time());
                $this->addFlash('error', sprintf('Espera %d segundos antes de solicitar otro enlace.', $seconds));

                return $this->redirectToRoute('app_request_password_reset');
            }

            $user = $userRepository->findByEmail($email);
            if (!$user instanceof User) {
                $this->addFlash('error', 'No existe una cuenta con ese correo.');

                return $this->redirectToRoute('app_request_password_reset');
            }

            $resetToken = bin2hex(random_bytes(32));
            $user
                ->setPasswordResetToken($resetToken)
                ->setPasswordResetExpiresAt(
                    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                        ->modify(sprintf('+%d minutes', self::RESET_TOKEN_TTL_MINUTES)),
                );

            $entityManager->flush();

            $resetUrl = $this->urlGenerator->generate(
                'app_reset_password',
                ['token' => $resetToken],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $this->sendResetEmail($mailer, $user, $resetUrl);

            $this->addFlash('success', 'Te enviamos un enlace de recuperación. Revisa tu correo.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/request_password_reset.html.twig', [
            'requestForm' => $form,
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        string $token,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = $userRepository->findOneBy(['passwordResetToken' => $token]);
        if (!$user instanceof User) {
            $this->addFlash('error', 'El enlace de recuperación es inválido.');

            return $this->redirectToRoute('app_request_password_reset');
        }

        $expiresAt = $user->getPasswordResetExpiresAt();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$expiresAt || $expiresAt < $now) {
            $this->addFlash('error', 'El enlace expiró. Solicita uno nuevo.');

            return $this->redirectToRoute('app_request_password_reset');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->get('first')->getData();
            $user
                ->setPassword($passwordHasher->hashPassword($user, $plainPassword))
                ->setPasswordResetToken(null)
                ->setPasswordResetExpiresAt(null);

            $entityManager->flush();

            $this->addFlash('success', 'Contraseña actualizada. Ya puedes iniciar sesión.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/reset_password.html.twig', [
            'resetForm' => $form,
        ]);
    }

    private function sendResetEmail(MailerInterface $mailer, User $user, string $resetUrl): void
    {
        $email = (new TemplatedEmail())
            ->from($this->mailerFromAddress)
            ->to($user->getEmail())
            ->subject('Recuperar contraseña - Quiniela 2026')
            ->htmlTemplate('emails/reset_password.html.twig')
            ->context([
                'user' => $user,
                'resetUrl' => $resetUrl,
                'expiresMinutes' => self::RESET_TOKEN_TTL_MINUTES,
            ]);

        try {
            $mailer->send($email);
            $this->logger->info('Password reset email sent.', [
                'to' => $user->getEmail(),
            ]);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Password reset email failed.', [
                'to' => $user->getEmail(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}

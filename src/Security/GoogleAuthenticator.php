<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RouterInterface $router,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $client = $this->clientRegistry->getClient('google_main');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(new UserBadge($accessToken->getToken(), function () use ($client, $accessToken): User {
            /** @var GoogleUser $googleUser */
            $googleUser = $client->fetchUserFromToken($accessToken);

            $googleId = $googleUser->getId();
            $email = (string) $googleUser->getEmail();
            $name = (string) ($googleUser->getName() ?? 'Usuario Google');

            $existing = $this->userRepository->findByGoogleId($googleId);
            if ($existing instanceof User) {
                return $existing;
            }

            $userByEmail = $this->userRepository->findByEmail($email);
            if ($userByEmail instanceof User) {
                $userByEmail->setGoogleId($googleId);
                $userByEmail->setIsVerified(true);
                $this->entityManager->persist($userByEmail);
                $this->entityManager->flush();

                return $userByEmail;
            }

            $user = (new User())
                ->setEmail($email)
                ->setName($name)
                ->setGoogleId($googleId)
                ->setIsVerified(true)
                ->setIsApproved(false)
                ->setRoles(['ROLE_USER']);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return $user;
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse($this->router->generate('app_login'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->router->generate('app_login'));
    }
}

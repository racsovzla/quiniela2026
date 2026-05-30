<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end test: register → verify email link → login.
 * Covers the full auth flow that was reported as broken.
 */
class RegistrationLoginFlowTest extends WebTestCase
{
    private const TEST_EMAIL = 'flow_test_auto@testdomain.invalid';
    private const TEST_PASSWORD = 'FlowTest1234';
    private const TEST_NAME = 'Flow Test User';

    protected function tearDown(): void
    {
        // Remove the test user after each test run
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $user = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);
            if ($user) {
                $em->remove($user);
                $em->flush();
            }
        } catch (\Throwable) {
            // Ignore cleanup errors
        }
        parent::tearDown();
    }

    /**
     * Step 1: Registration stores a valid password hash.
     */
    public function testRegistrationStoresValidPasswordHash(): void
    {
        $client = static::createClient();

        // GET /register — sets captcha in session
        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful('Register page must load');

        $captchaAnswer = $this->parseCaptchaAnswer($crawler);

        // Submit the registration form
        $form = $crawler->selectButton('Crear cuenta')->form([
            'registration_form[name]'                    => self::TEST_NAME,
            'registration_form[email]'                   => self::TEST_EMAIL,
            'registration_form[plainPassword][first]'    => self::TEST_PASSWORD,
            'registration_form[plainPassword][second]'   => self::TEST_PASSWORD,
            'registration_form[captchaAnswer]'           => $captchaAnswer,
        ]);
        $client->submit($form);

        self::assertResponseRedirects(
            '/verify/code',
            302,
            'After registration, should redirect to verify-code page'
        );

        // Verify user was persisted
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);

        self::assertNotNull($user, 'User must exist in DB after registration');
        self::assertNotEmpty($user->getPassword(), 'Password hash must be stored (not empty/null)');
        self::assertFalse($user->isVerified(), 'User must NOT be verified yet');

        // THE KEY CHECK: the stored hash must match the submitted plain password
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue(
            $hasher->isPasswordValid($user, self::TEST_PASSWORD),
            'Stored password hash must be valid for the submitted plain password. '
            . 'If this fails, the registration controller is hashing the wrong value (e.g. null or empty string).'
        );
    }

    /**
     * Step 2: Email verification link marks user as verified AND logs them in automatically.
     */
    public function testEmailVerificationLinkVerifiesAndLogsIn(): void
    {
        $client = static::createClient();

        // Register first
        $this->registerTestUser($client);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertNotNull($user);

        $token = $user->getEmailVerificationCode();
        self::assertNotEmpty($token, 'Verification token must be set after registration');

        // Hit the verification link
        $client->request('GET', '/verify/' . $token);
        self::assertResponseRedirects(null, 302, 'Verification link should redirect');

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertTrue($user->isVerified(), 'User must be verified after clicking the link');
        self::assertNull($user->getEmailVerificationCode(), 'Token must be cleared after verification');

        // Follow the redirect — user must be logged in (land on predictions, not login)
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $finalUrl = parse_url((string) $client->getRequest()->getUri(), PHP_URL_PATH);
        self::assertNotEquals('/login', $finalUrl, 'After verification, user must NOT land on login page — should be auto-logged in');
    }

    /**
     * Step 3 (full flow): Register → verify → login succeeds.
     */
    public function testFullFlowRegisterVerifyLogin(): void
    {
        $client = static::createClient();

        // 1. Register
        $this->registerTestUser($client);

        // 2. Verify email in DB directly (simulates clicking the link)
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertNotNull($user);

        $user->setIsVerified(true);
        $em->flush();
        $em->clear();

        // 3. GET login page to pick up fresh CSRF token
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful('Login page must load');

        $csrfToken = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotEmpty($csrfToken, 'CSRF token must be present on login form');

        // 4. POST credentials
        $client->request('POST', '/login', [
            '_username'    => self::TEST_EMAIL,
            '_password'    => self::TEST_PASSWORD,
            '_csrf_token'  => $csrfToken,
        ]);

        // Successful form_login redirects away from /login
        $location = $client->getResponse()->headers->get('Location');
        self::assertNotEquals(
            '/login',
            parse_url((string) $location, PHP_URL_PATH),
            'After successful login, must NOT redirect back to /login. '
            . 'If it redirects to /login again, authentication failed (wrong hash or wrong credentials).'
        );
        self::assertResponseRedirects(null, 302, 'Login must respond with a redirect');

        // 5. Follow redirect — must NOT land on login page
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsStringIgnoringCase(
            'Credenciales inválidas',
            $client->getResponse()->getContent(),
            'Should not see "Credenciales inválidas" after successful login'
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * GET /register, parse captcha, submit the form.
     * Returns the crawler after submission.
     */
    private function registerTestUser(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        // Remove any previous leftover from a prior failed run
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $existing = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);
            if ($existing) {
                $em->remove($existing);
                $em->flush();
                $em->clear();
            }
        } catch (\Throwable) {
        }

        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $captchaAnswer = $this->parseCaptchaAnswer($crawler);

        $form = $crawler->selectButton('Crear cuenta')->form([
            'registration_form[name]'                    => self::TEST_NAME,
            'registration_form[email]'                   => self::TEST_EMAIL,
            'registration_form[plainPassword][first]'    => self::TEST_PASSWORD,
            'registration_form[plainPassword][second]'   => self::TEST_PASSWORD,
            'registration_form[captchaAnswer]'           => $captchaAnswer,
        ]);
        $client->submit($form);
    }

    /**
     * Parse the captcha addition question from the register page and return the answer.
     * Question format: "Captcha: ¿Cuánto es X + Y?"
     */
    private function parseCaptchaAnswer(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        $captchaLabel = null;
        $crawler->filter('label.form-label')->each(function ($node) use (&$captchaLabel) {
            if (str_contains($node->text(), 'Cuánto es')) {
                $captchaLabel = $node->text();
            }
        });

        self::assertNotNull($captchaLabel, 'Captcha label must be present on register page');

        preg_match('/(\d+)\s*\+\s*(\d+)/', $captchaLabel, $matches);
        self::assertCount(3, $matches, 'Must parse two numbers from captcha label: ' . $captchaLabel);

        return (string) ((int) $matches[1] + (int) $matches[2]);
    }
}

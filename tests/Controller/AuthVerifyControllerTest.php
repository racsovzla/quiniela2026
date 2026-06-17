<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AuthVerifyControllerTest extends WebTestCase
{
    public function testResendCodeRejectsMissingCsrfToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/verify/resend', [
            'email' => 'user@example.com',
        ]);

        self::assertResponseRedirects('/verify/code');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Token inválido');
    }

    public function testVerifyPageContainsResendFormAndCsrfFields(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/verify/code');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('form input[name="_token"]')->count());
        self::assertGreaterThan(0, $crawler->filter('form[action="/verify/resend"]')->count());
    }
}

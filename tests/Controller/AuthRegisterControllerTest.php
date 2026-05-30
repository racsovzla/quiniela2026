<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AuthRegisterControllerTest extends WebTestCase
{
    public function testRegisterPageRendersCaptchaQuestion(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('label.form-label')->count());
        self::assertSelectorExists('form input[name="registration_form[captchaAnswer]"]');
        self::assertSelectorExists('form input[name="registration_form[_token]"]');
    }
}

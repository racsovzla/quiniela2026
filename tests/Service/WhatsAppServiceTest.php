<?php

namespace App\Tests\Service;

use App\Service\WhatsAppService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class WhatsAppServiceTest extends TestCase
{
    public function testSendMessageReturnsFalseWhenCredentialsMissing(): void
    {
        $service = new WhatsAppService(
            new MockHttpClient(),
            $this->createMock(LoggerInterface::class),
            '',
            'apikey',
        );

        self::assertFalse($service->sendMessage('test'));
    }

    public function testSendMessageReturnsTrueWhenCallMeBotQueuesMessage(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('<p><b>Message queued.</b> You will receive it in a few seconds.</p>'),
        ]);

        $service = new WhatsAppService(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            '+34123123123',
            '123456',
        );

        self::assertTrue($service->sendMessage('Hola quiniela'));
    }

    public function testSendMessageReturnsFalseWhenCallMeBotReturnsError(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('<p>Invalid apikey</p>'),
        ]);

        $service = new WhatsAppService(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            '+34123123123',
            'bad-key',
        );

        self::assertFalse($service->sendMessage('Hola quiniela'));
    }
}

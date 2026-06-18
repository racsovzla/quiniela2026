<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WhatsAppService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $phone,
        private readonly ?string $apiKey,
    ) {
    }

    public function sendMessage(string $message): bool
    {
        if (null === $this->phone || '' === $this->phone || null === $this->apiKey || '' === $this->apiKey) {
            $this->logger->warning('WhatsAppService: CALLMEBOT_PHONE or CALLMEBOT_APIKEY is not set. Cannot send message.');
            return false;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://api.callmebot.com/whatsapp.php', [
                'query' => [
                    'phone' => $this->phone,
                    'text' => $message,
                    'apikey' => $this->apiKey,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                return true;
            }

            $this->logger->error(sprintf('WhatsAppService: CallMeBot returned status code %d', $statusCode));
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('WhatsAppService: Failed to send message: %s', $exception->getMessage()));
        }

        return false;
    }
}

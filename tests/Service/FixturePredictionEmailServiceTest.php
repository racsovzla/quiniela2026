<?php

namespace App\Tests\Service;

use App\Entity\Fixture;
use App\Entity\Prediction;
use App\Entity\Team;
use App\Entity\User;
use App\Service\CountryNameResolver;
use App\Service\FixturePredictionEmailService;
use App\Service\WhatsAppMessageFormatter;
use App\Service\WhatsAppService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class FixturePredictionEmailServiceTest extends TestCase
{
    public function testSendFixturePredictionsSummarySendsEmailAndWhatsApp(): void
    {
        $homeTeam = (new Team())->setName('Home Team')->setCode('HOM');
        $awayTeam = (new Team())->setName('Away Team')->setCode('AWY');
        $fixture = (new Fixture())
            ->setHomeTeam($homeTeam)
            ->setAwayTeam($awayTeam)
            ->setKickoffAt(new \DateTimeImmutable('2026-06-11 19:00:00', new \DateTimeZone('UTC')));

        $user1 = (new User())->setName('Oscar')->setEmail('oscar@test.com');
        $user2 = (new User())->setName('Pedro')->setEmail('pedro@test.com');

        $prediction1 = (new Prediction())
            ->setUser($user1)
            ->setFixture($fixture)
            ->setPredictedHomeScore(2)
            ->setPredictedAwayScore(1);

        $prediction2 = (new Prediction())
            ->setUser($user2)
            ->setFixture($fixture)
            ->setPredictedHomeScore(0)
            ->setPredictedAwayScore(3);

        $predictions = [$prediction1, $prediction2];
        $recipients = [$user1, $user2];

        // Mails expectations
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))->method('send');

        $mailerFromAddress = new Address('no-reply@quiniela2026.local', 'Quiniela 2026');

        $countryNameResolver = $this->createMock(CountryNameResolver::class);
        $countryNameResolver->method('resolveSpanishName')->willReturnCallback(
            static fn ($code, $name) => $name
        );

        // WhatsApp expectations: should receive sendMessage with correct format
        $whatsAppService = $this->createMock(WhatsAppService::class);
        $whatsAppService
            ->expects(self::once())
            ->method('sendMessage')
            ->willReturn(true)
            ->with(self::callback(function (string $message) {
                return str_contains($message, '*Home Team* vs *Away Team*')
                    && str_contains($message, 'Oscar')
                    && str_contains($message, '*2-1*')
                    && str_contains($message, 'Pedro')
                    && str_contains($message, '*0-3*');
            }));

        $service = new FixturePredictionEmailService(
            $mailer,
            $mailerFromAddress,
            $countryNameResolver,
            $whatsAppService,
            new WhatsAppMessageFormatter(),
            $this->createMock(LoggerInterface::class),
        );

        $result = $service->sendFixturePredictionsSummary($fixture, $predictions, $recipients);

        self::assertSame(2, $result['emailsSent']);
        self::assertTrue($result['whatsAppSent']);
    }

    public function testSendFixturePredictionsSummarySendsWhatsAppEvenWhenLaterEmailFails(): void
    {
        $homeTeam = (new Team())->setName('Home Team')->setCode('HOM');
        $awayTeam = (new Team())->setName('Away Team')->setCode('AWY');
        $fixture = (new Fixture())
            ->setHomeTeam($homeTeam)
            ->setAwayTeam($awayTeam)
            ->setKickoffAt(new \DateTimeImmutable('2026-06-11 19:00:00', new \DateTimeZone('UTC')));

        $user1 = (new User())->setName('Oscar')->setEmail('oscar@test.com');
        $user2 = (new User())->setName('Pedro')->setEmail('pedro@test.com');

        $prediction1 = (new Prediction())
            ->setUser($user1)
            ->setFixture($fixture)
            ->setPredictedHomeScore(2)
            ->setPredictedAwayScore(1);

        $predictions = [$prediction1];
        $recipients = [$user1, $user2];

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects(self::exactly(2))
            ->method('send')
            ->willReturnCallback(function () {
                static $calls = 0;
                ++$calls;
                if ($calls === 2) {
                    throw new \RuntimeException('SMTP failure');
                }
            });

        $mailerFromAddress = new Address('no-reply@quiniela2026.local', 'Quiniela 2026');

        $countryNameResolver = $this->createMock(CountryNameResolver::class);
        $countryNameResolver->method('resolveSpanishName')->willReturnCallback(
            static fn ($code, $name) => $name
        );

        $whatsAppService = $this->createMock(WhatsAppService::class);
        $whatsAppService
            ->expects(self::once())
            ->method('sendMessage')
            ->willReturn(true);

        $service = new FixturePredictionEmailService(
            $mailer,
            $mailerFromAddress,
            $countryNameResolver,
            $whatsAppService,
            new WhatsAppMessageFormatter(),
            $this->createMock(LoggerInterface::class),
        );

        try {
            $service->sendFixturePredictionsSummary($fixture, $predictions, $recipients);
            self::fail('Expected RuntimeException from mailer.');
        } catch (\RuntimeException) {
        }
    }

    public function testSendFixturePredictionsSummaryExcludesPassiveUsers(): void
    {
        $homeTeam = (new Team())->setName('Home Team')->setCode('HOM');
        $awayTeam = (new Team())->setName('Away Team')->setCode('AWY');
        $fixture = (new Fixture())
            ->setHomeTeam($homeTeam)
            ->setAwayTeam($awayTeam)
            ->setKickoffAt(new \DateTimeImmutable('2026-06-11 19:00:00', new \DateTimeZone('UTC')));

        $user1 = (new User())->setName('Oscar')->setEmail('oscar@test.com')->setIsActive(true);
        $user2 = (new User())->setName('Pedro')->setEmail('pedro@test.com')->setIsActive(false);

        $prediction1 = (new Prediction())
            ->setUser($user1)
            ->setFixture($fixture)
            ->setPredictedHomeScore(2)
            ->setPredictedAwayScore(1);

        $prediction2 = (new Prediction())
            ->setUser($user2)
            ->setFixture($fixture)
            ->setPredictedHomeScore(0)
            ->setPredictedAwayScore(3);

        $predictions = [$prediction1, $prediction2];
        $recipients = [$user1];

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $mailerFromAddress = new Address('no-reply@quiniela2026.local', 'Quiniela 2026');

        $countryNameResolver = $this->createMock(CountryNameResolver::class);
        $countryNameResolver->method('resolveSpanishName')->willReturnCallback(
            static fn ($code, $name) => $name
        );

        $whatsAppService = $this->createMock(WhatsAppService::class);
        $whatsAppService
            ->expects(self::once())
            ->method('sendMessage')
            ->willReturn(true)
            ->with(self::callback(function (string $message) {
                return str_contains($message, '*Home Team* vs *Away Team*')
                    && str_contains($message, 'Oscar')
                    && str_contains($message, '*2-1*')
                    && !str_contains($message, 'Pedro')
                    && !str_contains($message, '0-3');
            }));

        $service = new FixturePredictionEmailService(
            $mailer,
            $mailerFromAddress,
            $countryNameResolver,
            $whatsAppService,
            new WhatsAppMessageFormatter(),
            $this->createMock(LoggerInterface::class),
        );

        $result = $service->sendFixturePredictionsSummary($fixture, $predictions, $recipients);

        self::assertSame(1, $result['emailsSent']);
        self::assertTrue($result['whatsAppSent']);
    }
}

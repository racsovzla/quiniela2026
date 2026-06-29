<?php

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\User;
use App\Repository\FixtureRepository;
use App\Repository\PredictionRepository;
use App\Repository\UserRepository;

class TestNextFixturePredictionsWhatsAppService
{
    private const MESSAGE_PREFIX = '🧪 [PRUEBA]';

    public function __construct(
        private readonly FixtureRepository $fixtureRepository,
        private readonly PredictionRepository $predictionRepository,
        private readonly UserRepository $userRepository,
        private readonly FixturePredictionEmailService $fixturePredictionEmailService,
        private readonly CountryNameResolver $countryNameResolver,
    ) {
    }

    /**
     * @return array{
     *     sent: bool,
     *     dryRun: bool,
     *     fixture: ?Fixture,
     *     admin: ?User,
     *     predictionCount: int,
     *     message: ?string,
     *     error: ?string
     * }
     */
    public function sendToAdmin(?\DateTimeImmutable $nowUtc = null, bool $dryRun = false): array
    {
        $nowUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $result = [
            'sent' => false,
            'dryRun' => $dryRun,
            'fixture' => null,
            'admin' => null,
            'predictionCount' => 0,
            'message' => null,
            'error' => null,
        ];

        $admins = $this->userRepository->findAdmins();
        if ($admins === []) {
            $result['error'] = 'No hay ningún usuario con ROLE_ADMIN.';

            return $result;
        }

        $admin = $admins[0];
        $result['admin'] = $admin;

        $fixture = $this->fixtureRepository->findNextNotStartedFixture($nowUtc);
        if (!$fixture instanceof Fixture) {
            $result['error'] = 'No hay partidos programados que aún no hayan empezado.';

            return $result;
        }

        $result['fixture'] = $fixture;

        $predictions = $this->predictionRepository->findByFixtureWithUser($fixture);
        $predictions = array_values(array_filter(
            $predictions,
            static fn (\App\Entity\Prediction $p): bool => $p->getUser()?->isActive() ?? false
        ));
        $result['predictionCount'] = count($predictions);
        if ($predictions === []) {
            $result['error'] = 'El próximo partido no tiene pronósticos registrados.';

            return $result;
        }

        $message = $this->fixturePredictionEmailService->buildFixturePredictionsWhatsAppMessage(
            $fixture,
            $predictions,
            self::MESSAGE_PREFIX,
        );
        if ($message === null) {
            $result['error'] = 'No se pudo construir el mensaje de WhatsApp.';

            return $result;
        }

        $result['message'] = $message;

        if ($dryRun) {
            return $result;
        }

        $result['sent'] = $this->fixturePredictionEmailService->sendFixturePredictionsWhatsApp(
            $fixture,
            $predictions,
            self::MESSAGE_PREFIX,
        );

        if (!$result['sent']) {
            $result['error'] = 'WhatsApp no se pudo enviar. Revisa CALLMEBOT_PHONE y CALLMEBOT_APIKEY.';
        }

        return $result;
    }

    public function describeFixture(Fixture $fixture): string
    {
        $homeTeamName = $this->countryNameResolver->resolveSpanishName(
            $fixture->getHomeTeam()?->getCode(),
            $fixture->getHomeTeam()?->getName()
        );
        $awayTeamName = $this->countryNameResolver->resolveSpanishName(
            $fixture->getAwayTeam()?->getCode(),
            $fixture->getAwayTeam()?->getName()
        );

        return sprintf(
            '%s vs %s (%s UTC)',
            $homeTeamName,
            $awayTeamName,
            $fixture->getKickoffAt()?->format('Y-m-d H:i') ?? 'sin fecha'
        );
    }
}

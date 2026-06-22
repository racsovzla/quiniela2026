<?php

namespace App\Controller;

use App\Service\FifaCalendarClient;
use App\Service\TournamentStandingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class PhasesController extends AbstractController
{
    /**
     * Read-only tournament view (group standings + knockout) fed live from the FIFA API,
     * independent of the app database. The API response is cached briefly.
     */
    #[Route('/fases', name: 'app_phases', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        FifaCalendarClient $fifaCalendarClient,
        TournamentStandingsService $standings,
        CacheInterface $cache,
    ): Response {
        $apiError = false;
        $rows = [];

        try {
            $rows = $cache->get('fifa_all_matches', static function (ItemInterface $item) use ($fifaCalendarClient): array {
                $item->expiresAfter(120);

                return $fifaCalendarClient->fetchAllMatches();
            });
        } catch (\Throwable) {
            $apiError = true;
        }

        return $this->render('phases/index.html.twig', [
            'groups' => $standings->standingsByGroup($rows),
            'knockout' => $standings->matchesByStage($rows),
            'apiError' => $apiError,
        ]);
    }
}

<?php

namespace App\Controller;

use App\Entity\Fixture;
use App\Entity\User;
use App\Repository\FixtureRepository;
use App\Repository\PredictionRepository;
use App\Service\FixturePredictionViewService;
use App\Service\PredictionSaveService;
use App\Service\PredictionWindowService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PredictionController extends AbstractController
{
    #[Route('/predictions', name: 'app_predictions', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        FixtureRepository $fixtureRepository,
        PredictionRepository $predictionRepository,
        FixturePredictionViewService $viewService,
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $paymentValidatedAt = $user->getPaymentValidatedAt();

        $fixtures = $fixtureRepository->findAllOrdered();
        $predictions = $predictionRepository->findByUserWithFixture($user);

        $predictionByFixture = [];
        foreach ($predictions as $prediction) {
            $fixture = $prediction->getFixture();
            if ($fixture) {
                $predictionByFixture[$fixture->getId()] = $prediction;
            }
        }

        $viewByFixture = [];
        $pendingCount = 0;

        foreach ($fixtures as $fixture) {
            $fixtureId = $fixture->getId();
            if (null === $fixtureId) {
                continue;
            }

            $prediction = $predictionByFixture[$fixtureId] ?? null;
            $view = $viewService->build($fixture, $prediction, $nowUtc, $paymentValidatedAt);
            $viewByFixture[$fixtureId] = $view;

            $hasPrediction = $prediction && $prediction->isCompleteForFixture();

            if (!$hasPrediction && $view['canEdit']) {
                $pendingCount++;
            }
        }

        usort($fixtures, function(Fixture $a, Fixture $b) use ($viewByFixture) {
            $aCanEdit = $viewByFixture[$a->getId()]['canEdit'] ?? false;
            $bCanEdit = $viewByFixture[$b->getId()]['canEdit'] ?? false;

            if ($aCanEdit !== $bCanEdit) {
                return $aCanEdit ? -1 : 1;
            }

            return $a->getKickoffAt() <=> $b->getKickoffAt();
        });

        return $this->render('prediction/index.html.twig', [
            'fixtures' => $fixtures,
            'predictionByFixture' => $predictionByFixture,
            'viewByFixture' => $viewByFixture,
            'canParticipate' => $user->isVerified(),
            'paymentValidatedAt' => $paymentValidatedAt,
            'pendingCount' => $pendingCount,
        ]);
    }

    #[Route('/predictions/{id}', name: 'app_prediction_save', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function save(
        Fixture $fixture,
        Request $request,
        PredictionSaveService $predictionSaveService,
        PredictionWindowService $predictionWindowService,
    ): RedirectResponse|JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('save_prediction_'.$fixture->getId(), (string) $request->request->get('_token'))) {
            if ($isAjax) {
                return new JsonResponse(['error' => 'CSRF inválido.'], 422);
            }
            $this->addFlash('error', 'CSRF inválido.');

            return $this->redirectToRoute('app_predictions');
        }

        if (!$user->isVerified()) {
            if ($isAjax) {
                return new JsonResponse(['error' => 'Debes verificar tu correo para participar.'], 403);
            }
            $this->addFlash('error', 'Debes verificar tu correo para participar.');

            return $this->redirectToRoute('app_predictions');
        }

        if (!$predictionWindowService->canEdit($fixture)) {
            if ($isAjax) {
                return new JsonResponse(['error' => 'No se puede editar. La ventana cerró 5 minutos antes del partido.'], 422);
            }
            $this->addFlash('error', 'No se puede editar. La ventana cerró 5 minutos antes del partido.');

            return $this->redirectToRoute('app_predictions');
        }

        $result = $predictionSaveService->save(
            $user,
            $fixture,
            $request->request->get('home'),
            $request->request->get('away'),
            $request->request->get('penalty_home'),
            $request->request->get('penalty_away'),
        );

        if (!$result['ok']) {
            if ($isAjax) {
                return new JsonResponse(['error' => $result['error']], 422);
            }
            $this->addFlash('error', $result['error']);

            return $this->redirectToRoute('app_predictions');
        }

        if ($isAjax) {
            return new JsonResponse(['success' => true]);
        }

        $this->addFlash('success', 'Predicción guardada.');

        return $this->redirectToRoute('app_predictions');
    }
}

<?php

namespace App\Controller;

use App\Entity\Fixture;
use App\Entity\Prediction;
use App\Entity\User;
use App\Repository\FixtureRepository;
use App\Repository\PredictionRepository;
use App\Service\PredictionWindowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        PredictionWindowService $predictionWindowService,
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $fixtures = $fixtureRepository->findAllOrdered();
        $predictions = $predictionRepository->findByUserWithFixture($user);

        $predictionByFixture = [];
        foreach ($predictions as $prediction) {
            $fixture = $prediction->getFixture();
            if ($fixture) {
                $predictionByFixture[$fixture->getId()] = $prediction;
            }
        }

        $fixtureCanEdit = [];
        $fixtureDeadlineIso = [];
        foreach ($fixtures as $fixture) {
            $fixtureId = $fixture->getId();
            if (null === $fixtureId) {
                continue;
            }

            $deadline = $predictionWindowService->deadline($fixture);
            $fixtureCanEdit[$fixtureId] = $predictionWindowService->canEditAt($fixture, $nowUtc);
            $fixtureDeadlineIso[$fixtureId] = $deadline->format(DATE_ATOM);
        }

        return $this->render('prediction/index.html.twig', [
            'fixtures' => $fixtures,
            'predictionByFixture' => $predictionByFixture,
            'fixtureCanEdit' => $fixtureCanEdit,
            'fixtureDeadlineIso' => $fixtureDeadlineIso,
            'canParticipate' => $user->isVerified() && $user->isApproved(),
            'paymentValidatedAt' => $user->getPaymentValidatedAt(),
        ]);
    }

    #[Route('/predictions/{id}', name: 'app_prediction_save', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function save(
        Fixture $fixture,
        Request $request,
        PredictionRepository $predictionRepository,
        EntityManagerInterface $entityManager,
        PredictionWindowService $predictionWindowService,
    ): RedirectResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('save_prediction_'.$fixture->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'CSRF inválido.');

            return $this->redirectToRoute('app_predictions');
        }

        if (!$user->isVerified()) {
            $this->addFlash('error', 'Debes verificar tu correo para participar.');

            return $this->redirectToRoute('app_predictions');
        }

        if (!$user->isApproved()) {
            $this->addFlash('error', 'Tu cuenta está pendiente de aprobación por admin.');

            return $this->redirectToRoute('app_predictions');
        }

        if (!$predictionWindowService->canEdit($fixture)) {
            $this->addFlash('error', 'No se puede editar. La ventana cerró 5 minutos antes del partido.');

            return $this->redirectToRoute('app_predictions');
        }

        $home = filter_var($request->request->get('home'), FILTER_VALIDATE_INT);
        $away = filter_var($request->request->get('away'), FILTER_VALIDATE_INT);

        if ($home === false || $away === false || $home < 0 || $away < 0) {
            $this->addFlash('error', 'Marcador inválido. Debe ser entero mayor o igual a 0.');

            return $this->redirectToRoute('app_predictions');
        }

        $prediction = $predictionRepository->findOneByUserAndFixture($user, $fixture);
        if (!$prediction instanceof Prediction) {
            $prediction = (new Prediction())
                ->setUser($user)
                ->setFixture($fixture);
            $entityManager->persist($prediction);
        }

        $prediction
            ->setPredictedHomeScore((int) $home)
            ->setPredictedAwayScore((int) $away);

        $entityManager->flush();

        $this->addFlash('success', 'Predicción guardada.');

        return $this->redirectToRoute('app_predictions');
    }
}

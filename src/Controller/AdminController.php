<?php

namespace App\Controller;

use App\Entity\Fixture;
use App\Entity\Team;
use App\Entity\TournamentGroup;
use App\Entity\User;
use App\Form\FixtureType;
use App\Form\TeamType;
use App\Form\TournamentGroupType;
use App\Repository\FixtureRepository;
use App\Repository\PredictionRepository;
use App\Repository\TeamRepository;
use App\Repository\TournamentGroupRepository;
use App\Repository\UserRepository;
use App\Service\FixturePredictionEmailService;
use App\Service\SimulationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(
        UserRepository $userRepository,
        TeamRepository $teamRepository,
        FixtureRepository $fixtureRepository,
        TournamentGroupRepository $groupRepository,
    ): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'pendingUsers' => $userRepository->findBy(['isApproved' => false], ['createdAt' => 'ASC']),
            'approvedUsers' => $userRepository->findBy(['isApproved' => true], ['createdAt' => 'ASC']),
            'groups' => $groupRepository->findBy([], ['code' => 'ASC']),
            'teams' => $teamRepository->findBy([], ['name' => 'ASC']),
            'fixtures' => $fixtureRepository->findAllOrderedForAdmin(),
        ]);
    }

    #[Route('/groups/new', name: 'admin_group_new')]
    public function newGroup(Request $request, EntityManagerInterface $entityManager): Response
    {
        $group = new TournamentGroup();
        $form = $this->createForm(TournamentGroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($group);
            $entityManager->flush();

            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/group_form.html.twig', ['form' => $form]);
    }

    #[Route('/groups/{id}/edit', name: 'admin_group_edit')]
    public function editGroup(TournamentGroup $group, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(TournamentGroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/group_form.html.twig', ['form' => $form]);
    }

    #[Route('/users/{id}/approve', name: 'admin_user_approve', methods: ['POST'])]
    public function approveUser(User $user, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('approve_user_'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'CSRF inválido.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $user->setIsApproved(true);
        $entityManager->flush();
        $this->addFlash('success', 'Usuario aprobado.');

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/users/{id}/validate-payment', name: 'admin_user_validate_payment', methods: ['POST'])]
    public function validateUserPayment(User $user, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('validate_payment_'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'CSRF inválido.');

            return $this->redirectToRoute('admin_dashboard');
        }

        if (!$user->isApproved()) {
            $this->addFlash('error', 'Primero debes aprobar al usuario.');

            return $this->redirectToRoute('admin_dashboard');
        }

        if ($user->isPaymentValidated()) {
            $this->addFlash('warning', 'El pago de este usuario ya estaba validado.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $user->setPaymentValidatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $entityManager->flush();

        $this->addFlash('success', sprintf('Pago validado para %s.', $user->getEmail()));

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/teams/new', name: 'admin_team_new')]
    public function newTeam(Request $request, EntityManagerInterface $entityManager): Response
    {
        $team = new Team();
        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($team);
            $entityManager->flush();

            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/team_form.html.twig', ['form' => $form]);
    }

    #[Route('/teams/{id}/edit', name: 'admin_team_edit')]
    public function editTeam(Team $team, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/team_form.html.twig', ['form' => $form]);
    }

    #[Route('/fixtures/new', name: 'admin_fixture_new')]
    public function newFixture(Request $request, EntityManagerInterface $entityManager): Response
    {
        $fixture = new Fixture();
        $fixture->setKickoffAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $form = $this->createForm(FixtureType::class, $fixture);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($fixture->getHomeTeam()?->getId() === $fixture->getAwayTeam()?->getId()) {
                $this->addFlash('error', 'Local y visitante no pueden ser el mismo equipo.');

                return $this->render('admin/fixture_form.html.twig', ['form' => $form]);
            }

            $entityManager->persist($fixture);
            $entityManager->flush();

            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/fixture_form.html.twig', ['form' => $form]);
    }

    #[Route('/fixtures/{id}/edit', name: 'admin_fixture_edit')]
    public function editFixture(
        Fixture $fixture,
        Request $request,
        EntityManagerInterface $entityManager,
        PredictionRepository $predictionRepository,
        UserRepository $userRepository,
    ): Response
    {
        $form = $this->createForm(FixtureType::class, $fixture);
        $form->handleRequest($request);

        $predictions = $predictionRepository->findByFixtureWithUser($fixture);
        $allUsers = $userRepository->findApprovedVerifiedRecipients();
        $predictedUserIds = [];
        foreach ($predictions as $prediction) {
            $predictedUserIds[$prediction->getUser()?->getId()] = true;
        }

        $missingUsers = [];
        foreach ($allUsers as $user) {
            if (!isset($predictedUserIds[$user->getId()])) {
                $missingUsers[] = $user;
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if ($fixture->getHomeTeam()?->getId() === $fixture->getAwayTeam()?->getId()) {
                $this->addFlash('error', 'Local y visitante no pueden ser el mismo equipo.');

                return $this->render('admin/fixture_form.html.twig', [
                    'form' => $form,
                    'predictions' => $predictions,
                    'missingUsers' => $missingUsers,
                ]);
            }

            $entityManager->flush();

            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/fixture_form.html.twig', [
            'form' => $form,
            'predictions' => $predictions,
            'missingUsers' => $missingUsers,
        ]);
    }

    #[Route('/simulation/seed', name: 'admin_simulation_seed', methods: ['POST'])]
    public function seedSimulation(Request $request, SimulationService $simulationService): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_simulation_seed', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'CSRF inválido.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $result = $simulationService->seedUsersAndPredictions(10);

        $this->addFlash(
            'success',
            sprintf(
                'Simulación cargada: usuarios nuevos %d, predicciones nuevas %d.',
                $result['createdUsers'],
                $result['createdPredictions']
            )
        );

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/simulation/simulate-next', name: 'admin_simulation_simulate_next', methods: ['POST'])]
    public function simulateNextFixture(Request $request, SimulationService $simulationService): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_simulation_simulate_next', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'CSRF inválido.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $fixture = $simulationService->simulateNextFixtureScore();

        if (!$fixture instanceof Fixture) {
            $this->addFlash('warning', 'No hay partidos programados para simular.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $this->addFlash(
            'success',
            sprintf(
                'Resultado simulado: %s %d - %d %s (sigue en programado).',
                $fixture->getHomeTeam()?->getCode() ?? '-',
                $fixture->getHomeScore() ?? 0,
                $fixture->getAwayScore() ?? 0,
                $fixture->getAwayTeam()?->getCode() ?? '-'
            )
        );

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/simulation/finalize-in-progress', name: 'admin_simulation_finalize_in_progress', methods: ['POST'])]
    public function finalizeInProgressFixture(Request $request, SimulationService $simulationService): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_simulation_finalize_in_progress', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'CSRF inválido.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $fixture = $simulationService->finalizeInProgressFixture();

        if (!$fixture instanceof Fixture) {
            $this->addFlash('warning', 'No hay partidos en curso para finalizar.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $this->addFlash(
            'success',
            sprintf(
                'Partido finalizado: %s vs %s.',
                $fixture->getHomeTeam()?->getCode() ?? '-',
                $fixture->getAwayTeam()?->getCode() ?? '-'
            )
        );

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/fixtures/send-predictions-email', name: 'admin_fixture_send_predictions_email', methods: ['POST'])]
    public function sendFixturePredictionsEmail(
        Request $request,
        FixtureRepository $fixtureRepository,
        PredictionRepository $predictionRepository,
        UserRepository $userRepository,
        FixturePredictionEmailService $fixturePredictionEmailService,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('send_fixture_predictions_email', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'CSRF inválido.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $fixtureId = filter_var($request->request->get('fixture_id'), FILTER_VALIDATE_INT);
        if (false === $fixtureId || $fixtureId <= 0) {
            $this->addFlash('error', 'Selecciona un partido válido.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $fixture = $fixtureRepository->find($fixtureId);
        if (!$fixture instanceof Fixture) {
            $this->addFlash('error', 'No se encontró el partido seleccionado.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $predictions = $predictionRepository->findByFixtureWithUser($fixture);
        if ($predictions === []) {
            $this->addFlash('warning', 'Ese partido todavía no tiene predicciones para enviar.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $recipients = $userRepository->findApprovedVerifiedRecipients();
        if ($recipients === []) {
            $this->addFlash('warning', 'No hay usuarios aprobados y verificados para recibir el correo.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $sentCount = $fixturePredictionEmailService->sendFixturePredictionsSummary($fixture, $predictions, $recipients);

        $fixture->setPredictionsEmailSentAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $fixtureRepository->getEntityManager()->flush();

        $this->addFlash('success', sprintf('Resumen enviado para %s destinatarios.', $sentCount));

        return $this->redirectToRoute('admin_dashboard');
    }
}

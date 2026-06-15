<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('plainPassword')->get('first')->getData();
            $canSave = true;

            if (is_string($newPassword) && $newPassword !== '') {
                $currentPassword = (string) $form->get('currentPassword')->getData();
                if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $form->get('currentPassword')->addError(new FormError('La contraseña actual no es correcta.'));
                    $canSave = false;
                } else {
                    $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
                }
            }

            if ($canSave) {
                $entityManager->flush();
                $this->addFlash('success', 'Tus datos se actualizaron correctamente.');

                return $this->redirectToRoute('app_profile');
            }
        }

        return $this->render('profile/index.html.twig', [
            'profileForm' => $form,
            'userEmail' => $user->getEmail(),
        ]);
    }
}

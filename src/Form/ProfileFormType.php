<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Contraseña actual',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new Callback(function (mixed $value, ExecutionContextInterface $context): void {
                        $form = $context->getRoot();
                        if (!$form instanceof FormInterface) {
                            return;
                        }

                        $newPassword = $form->get('plainPassword')->get('first')->getData();
                        if ($newPassword !== null && $newPassword !== '' && ($value === null || $value === '')) {
                            $context->buildViolation('Indica tu contraseña actual para cambiarla.')
                                ->addViolation();
                        }
                    }),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'mapped' => false,
                'required' => false,
                'type' => PasswordType::class,
                'invalid_message' => 'Las contraseñas no coinciden.',
                'first_options' => [
                    'label' => 'Nueva contraseña',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'minlength' => 8,
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirmar nueva contraseña',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'minlength' => 8,
                    ],
                ],
                'constraints' => [
                    new Callback(function (mixed $value, ExecutionContextInterface $context): void {
                        $password = is_string($value) ? $value : null;
                        if ($password === null || $password === '') {
                            return;
                        }

                        if (strlen($password) < 8) {
                            $context->buildViolation('La contraseña debe tener al menos 8 caracteres.')
                                ->addViolation();

                            return;
                        }

                        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $password)) {
                            $context->buildViolation('Usa al menos una minúscula, una mayúscula y un número.')
                                ->addViolation();
                        }
                    }),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}

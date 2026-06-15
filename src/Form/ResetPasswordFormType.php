<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
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
                'label' => 'Confirmar contraseña',
                'attr' => [
                    'autocomplete' => 'new-password',
                    'minlength' => 8,
                ],
            ],
            'constraints' => [
                new NotBlank(message: 'Indica una contraseña.'),
                new Length(min: 8, max: 255),
                new Regex(
                    pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
                    message: 'Usa al menos una minúscula, una mayúscula y un número.',
                ),
            ],
        ]);
    }
}

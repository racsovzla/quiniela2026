<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class RequestPasswordResetFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Correo',
            'attr' => [
                'placeholder' => 'tu@email.com',
                'autocomplete' => 'email',
            ],
            'constraints' => [
                new NotBlank(message: 'Indica tu correo.'),
                new Email(message: 'Correo inválido.'),
            ],
        ]);
    }
}

<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('email', EmailType::class)
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Las contraseñas no coinciden.',
                'first_options' => [
                    'label' => false,
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'minlength' => 8,
                    ],
                ],
                'second_options' => [
                    'label' => false,
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'minlength' => 8,
                    ],
                ],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 8, max: 255),
                    new Regex(
                        pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
                        message: 'Usa al menos una minúscula, una mayúscula y un número.'
                    ),
                ],
            ])
            ->add('captchaAnswer', TextType::class, [
                'mapped' => false,
                'label' => false,
                'attr' => [
                    'inputmode' => 'numeric',
                    'autocomplete' => 'off',
                ],
                'constraints' => [
                    new NotBlank(message: 'Resuelve el captcha para continuar.'),
                    new Regex(
                        pattern: '/^\d+$/',
                        message: 'El captcha debe ser numérico.'
                    ),
                    new Callback(function (mixed $value, ExecutionContextInterface $context) use ($options): void {
                        $expected = trim((string) $options['captcha_expected_answer']);
                        $provided = trim((string) $value);

                        if ($expected === '' || $provided !== $expected) {
                            $context->buildViolation('Captcha incorrecto. Intenta de nuevo.')
                                ->addViolation();
                        }
                    }),
                ],
            ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['captcha_question'] = $options['captcha_question'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'captcha_question' => '',
            'captcha_expected_answer' => '',
        ]);

        $resolver->setAllowedTypes('captcha_question', 'string');
        $resolver->setAllowedTypes('captcha_expected_answer', 'string');
    }
}

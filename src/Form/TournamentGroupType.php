<?php

namespace App\Form;

use App\Entity\TournamentGroup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TournamentGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, ['help' => 'A-L o fase: r32, r16, qf, sf, final, third'])
            ->add('name', TextType::class, ['help' => 'Ejemplo: Grupo A']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TournamentGroup::class,
        ]);
    }
}

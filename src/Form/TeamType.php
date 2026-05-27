<?php

namespace App\Form;

use App\Entity\Team;
use App\Entity\TournamentGroup;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TeamType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('code', TextType::class, ['help' => '3 letras en mayúscula, por ejemplo ARG'])
            ->add('group', EntityType::class, [
                'class' => TournamentGroup::class,
                'choice_label' => static fn (TournamentGroup $group): string => $group->getCode().' - '.$group->getName(),
                'required' => false,
                'placeholder' => 'Sin grupo',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Team::class,
        ]);
    }
}

<?php

namespace App\Form;

use App\Entity\Fixture;
use App\Entity\Team;
use App\Entity\TournamentGroup;
use App\Service\CountryNameResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FixtureType extends AbstractType
{
    public function __construct(private readonly CountryNameResolver $countryNameResolver)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('homeTeam', EntityType::class, [
                'class' => Team::class,
                'choice_label' => fn (Team $team): string => $this->countryNameResolver->resolveSpanishName($team->getCode(), $team->getName()),
            ])
            ->add('awayTeam', EntityType::class, [
                'class' => Team::class,
                'choice_label' => fn (Team $team): string => $this->countryNameResolver->resolveSpanishName($team->getCode(), $team->getName()),
            ])
            ->add('group', EntityType::class, [
                'class' => TournamentGroup::class,
                'choice_label' => static fn (TournamentGroup $group): string => $group->getCode().' - '.$group->getName(),
                'required' => false,
                'placeholder' => 'Sin grupo',
                'help' => 'Recomendado para leaderboard por grupo. Dejar vacío en eliminatorias.',
            ])
            ->add('stage', ChoiceType::class, [
                'choices' => [
                    'Grupos' => Fixture::STAGE_GROUP,
                    'Dieciseisavos' => Fixture::STAGE_R32,
                    'Octavos' => Fixture::STAGE_R16,
                    'Cuartos' => Fixture::STAGE_QF,
                    'Semifinal' => Fixture::STAGE_SF,
                    'Final' => Fixture::STAGE_FINAL,
                    '3er puesto' => Fixture::STAGE_THIRD,
                ],
            ])
            ->add('fifaMatchId', TextType::class, ['required' => false, 'label' => 'FIFA Match ID'])
            ->add('kickoffAt', DateTimeType::class, [
                'widget' => 'single_text',
                'model_timezone' => 'UTC',
                'view_timezone' => 'UTC',
            ])
            ->add('status', ChoiceType::class, [
                'choices' => [
                    'Programado' => Fixture::STATUS_SCHEDULED,
                    'Finalizado' => Fixture::STATUS_FINISHED,
                ],
            ])
            ->add('homeScore', IntegerType::class, ['required' => false])
            ->add('awayScore', IntegerType::class, ['required' => false])
            ->add('penaltyHomeScore', IntegerType::class, ['required' => false, 'label' => 'Penales local'])
            ->add('penaltyAwayScore', IntegerType::class, ['required' => false, 'label' => 'Penales visitante']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Fixture::class,
        ]);
    }
}

<?php

namespace App\Form;

use App\Entity\Ranking;
use App\Enum\CatalogItemKind;
use App\Form\Data\CustomRankingData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CustomRankingData>
 */
final class CustomRankingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de la liste',
                'attr' => ['maxlength' => Ranking::NAME_MAX_LENGTH, 'placeholder' => 'Mes albums de l\'été'],
            ])
            ->add('kind', EnumType::class, [
                'label' => 'Ce que tu classes',
                'class' => CatalogItemKind::class,
                'choices' => $options['kinds'],
                'choice_label' => static fn (CatalogItemKind $kind): string => $kind->label(),
                'expanded' => true,
            ])
            ->add('maxSize', IntegerType::class, [
                'label' => 'Nombre de places',
                'required' => false,
                'help' => 'Laisse vide pour une liste sans limite.',
                'attr' => ['min' => 1, 'max' => CustomRankingData::MAX_SIZE],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CustomRankingData::class,
            // Types proposés : tous, ou ceux compatibles avec le périmètre choisi.
            'kinds' => CatalogItemKind::cases(),
        ]);
        $resolver->setAllowedTypes('kinds', CatalogItemKind::class.'[]');
    }
}

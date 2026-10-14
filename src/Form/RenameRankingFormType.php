<?php

namespace App\Form;

use App\Entity\Ranking;
use App\Form\Data\RenameRankingData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<RenameRankingData>
 */
final class RenameRankingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nom de la liste',
            'attr' => ['maxlength' => Ranking::NAME_MAX_LENGTH],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RenameRankingData::class]);
    }
}

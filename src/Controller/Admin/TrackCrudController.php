<?php

namespace App\Controller\Admin;

use App\Entity\Track;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<Track>
 */
#[IsGranted('ROLE_ADMIN')]
final class TrackCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Track::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Titre')
            ->setEntityLabelInPlural('Titres')
            ->setDefaultSort(['album.name' => 'ASC', 'discNumber' => 'ASC', 'position' => 'ASC'])
            ->setSearchFields(['name', 'album.name', 'album.artist.name']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Titre');
        yield AssociationField::new('album', 'Album')->hideOnForm();
        yield IntegerField::new('discNumber', 'Disque')->hideOnForm();
        yield IntegerField::new('position', 'Piste')->hideOnForm();
        yield TextField::new('formattedDuration', 'Durée')->onlyOnIndex();
        yield BooleanField::new('hidden', 'Masqué')->renderAsSwitch();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('album')->add('hidden');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW);
    }
}

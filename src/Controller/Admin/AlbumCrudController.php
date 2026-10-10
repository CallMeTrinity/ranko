<?php

namespace App\Controller\Admin;

use App\Catalog\CatalogImporter;
use App\Entity\Album;
use App\Enum\AlbumType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<Album>
 */
#[IsGranted('ROLE_ADMIN')]
final class AlbumCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly CatalogImporter $importer,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Album::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Album')
            ->setEntityLabelInPlural('Albums')
            ->setDefaultSort(['artist.name' => 'ASC', 'releaseDate' => 'ASC'])
            ->setSearchFields(['name', 'artist.name']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield Field::new('coverUrl', 'Pochette')
            ->setTemplatePath('admin/field/cover.html.twig')
            ->onlyOnIndex();
        yield TextField::new('name', 'Titre');
        yield AssociationField::new('artist', 'Artiste')->hideOnForm();
        yield ChoiceField::new('type', 'Type')->setChoices(AlbumType::cases())->hideOnForm();
        yield ArrayField::new('secondaryTypes', 'Types secondaires')->hideOnForm();
        yield TextField::new('releaseDate', 'Sortie')->hideOnForm();
        yield BooleanField::new('hidden', 'Masqué')->renderAsSwitch();
        yield AssociationField::new('tracks', 'Titres')->onlyOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('artist')->add('type')->add('hidden');
    }

    public function configureActions(Actions $actions): Actions
    {
        $importTracks = Action::new('importTracks', 'Importer les titres', 'fa fa-rotate')
            ->linkToRoute('admin_album_import_tracks', fn (Album $album): array => [
                'id' => $album->getId(),
                'token' => $this->csrfTokenManager->getToken('import-tracks'.$album->getId())->getValue(),
            ])
            ->renderAsForm();

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, $importTracks)
            ->add(Crud::PAGE_DETAIL, $importTracks);
    }

    /**
     * Rendre visible un album jamais importé (live, compilation) déclenche l'import de
     * ses titres. Passe aussi par ici pour le switch "Masqué" de la liste.
     */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);

        if (!$entityInstance->isHidden() && null === $entityInstance->getTracksImportedAt()) {
            $this->importer->requestTracksImport($entityInstance);
        }
    }

    #[AdminRoute('/{id}/import-tracks', name: 'import_tracks', options: ['methods' => ['POST']])]
    #[IsCsrfTokenValid(new Expression('"import-tracks" ~ args["album"].getId()'), tokenKey: 'token', tokenSource: IsCsrfTokenValid::SOURCE_QUERY)]
    public function importTracks(Album $album): Response
    {
        $this->importer->requestTracksImport($album);

        $this->addFlash('success', sprintf('Import des titres de "%s" planifié.', $album->getName()));

        return $this->redirectToRoute('admin_album_index');
    }
}

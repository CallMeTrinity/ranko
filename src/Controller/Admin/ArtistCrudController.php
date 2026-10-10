<?php

namespace App\Controller\Admin;

use App\Catalog\CatalogImporter;
use App\Entity\Artist;
use App\Enum\ImportStatus;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<Artist>
 */
#[IsGranted('ROLE_ADMIN')]
final class ArtistCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Artist::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Artiste')
            ->setEntityLabelInPlural('Artistes')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name', 'sortName']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield TextField::new('disambiguation', 'Précision')->hideOnIndex();
        yield BooleanField::new('hidden', 'Masqué')->renderAsSwitch();
        yield AssociationField::new('albums', 'Albums')->onlyOnIndex();
        yield ChoiceField::new('importStatus', 'Import')
            ->setChoices(ImportStatus::cases())
            ->renderAsBadges([
                ImportStatus::Pending->value => 'secondary',
                ImportStatus::Running->value => 'info',
                ImportStatus::Done->value => 'success',
                ImportStatus::Failed->value => 'danger',
            ])
            ->hideOnForm();
        yield DateTimeField::new('importedAt', 'Importé le')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('hidden')->add('importStatus');
    }

    public function configureActions(Actions $actions): Actions
    {
        $reimport = Action::new('reimport', 'Réimporter', 'fa fa-rotate')
            ->linkToRoute('admin_artist_reimport', fn (Artist $artist): array => [
                'id' => $artist->getId(),
                'token' => $this->csrfTokenManager->getToken('reimport'.$artist->getId())->getValue(),
            ])
            ->renderAsForm();

        $import = Action::new('import', 'Importer un artiste', 'fa fa-cloud-arrow-down')
            ->linkToRoute('admin_artist_import_index')
            ->createAsGlobalAction()
            ->asPrimaryAction();

        // Les artistes viennent toujours d'un import : pas de création à la main.
        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, $import)
            ->add(Crud::PAGE_INDEX, $reimport)
            ->add(Crud::PAGE_DETAIL, $reimport);
    }

    #[AdminRoute('/{id}/reimport', name: 'reimport', options: ['methods' => ['POST']])]
    #[IsCsrfTokenValid(new Expression('"reimport" ~ args["artist"].getId()'), tokenKey: 'token', tokenSource: IsCsrfTokenValid::SOURCE_QUERY)]
    public function reimport(Artist $artist, CatalogImporter $importer): Response
    {
        $importer->requestDiscographyImport($artist);

        $this->addFlash('success', sprintf('Réimport de "%s" planifié.', $artist->getName()));

        return $this->redirectToRoute('admin_artist_index');
    }
}

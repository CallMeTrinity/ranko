<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Spotify\SpotifyAccess;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Liste des comptes, en lecture seule, et attribution des places Spotify.
 *
 * @extends AbstractCrudController<User>
 */
#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly SpotifyAccess $spotify,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilisateur')
            ->setEntityLabelInPlural('Utilisateurs')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['username', 'email'])
            // Le titre plutôt que l'aide, qu'EasyAdmin cache derrière une icône.
            ->setPageTitle(Crud::PAGE_INDEX, sprintf('Utilisateurs · Spotify %d / %d places', $this->spotify->usedSeats(), $this->spotify->maxUsers()))
            ->setHelp(Crud::PAGE_INDEX, 'Avant d\'activer Spotify pour quelqu\'un, ajoute l\'email de son compte Spotify dans le dashboard Spotify (User Management), sinon Spotify refusera sa connexion.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('username', 'Pseudo');
        yield EmailField::new('email', 'Email');
        yield DateTimeField::new('createdAt', 'Inscrit le')->setFormat('dd/MM/yyyy');
        yield Field::new('roles', 'Spotify')
            ->setTemplatePath('admin/field/spotify.html.twig')
            ->setSortable(false)
            ->onlyOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        $grant = Action::new('grantSpotify', 'Activer Spotify', 'fa fa-music')
            ->linkToRoute('admin_user_grant_spotify', fn (User $user): array => $this->routeParameters('grant-spotify', $user))
            ->displayIf(static fn (User $user): bool => !$user->hasRole(SpotifyAccess::ROLE))
            ->renderAsForm();

        $revoke = Action::new('revokeSpotify', 'Retirer Spotify', 'fa fa-ban')
            ->linkToRoute('admin_user_revoke_spotify', fn (User $user): array => $this->routeParameters('revoke-spotify', $user))
            ->displayIf(static fn (User $user): bool => $user->hasRole(SpotifyAccess::ROLE))
            ->renderAsForm();

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $grant)
            ->add(Crud::PAGE_INDEX, $revoke);
    }

    #[AdminRoute('/{id}/grant-spotify', name: 'grant_spotify', options: ['methods' => ['POST']])]
    #[IsCsrfTokenValid(new Expression('"grant-spotify" ~ args["user"].getId()'), tokenKey: 'token', tokenSource: IsCsrfTokenValid::SOURCE_QUERY)]
    public function grantSpotify(User $user): Response
    {
        try {
            $this->spotify->grant($user);
            $this->addFlash('success', sprintf('Spotify activé pour %s. Son email Spotify doit aussi être dans le dashboard Spotify.', $user->getUsername()));
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_user_index');
    }

    #[AdminRoute('/{id}/revoke-spotify', name: 'revoke_spotify', options: ['methods' => ['POST']])]
    #[IsCsrfTokenValid(new Expression('"revoke-spotify" ~ args["user"].getId()'), tokenKey: 'token', tokenSource: IsCsrfTokenValid::SOURCE_QUERY)]
    public function revokeSpotify(User $user): Response
    {
        $this->spotify->revoke($user);
        $this->addFlash('success', sprintf('Spotify retiré à %s. Retire aussi son email du dashboard Spotify pour libérer la place.', $user->getUsername()));

        return $this->redirectToRoute('admin_user_index');
    }

    /**
     * @return array{id: ?int, token: string}
     */
    private function routeParameters(string $action, User $user): array
    {
        return [
            'id' => $user->getId(),
            'token' => $this->csrfTokenManager->getToken($action.$user->getId())->getValue(),
        ];
    }
}

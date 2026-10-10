<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->redirectToRoute('admin_artist_index');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Ranko · back-office');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::section('Catalogue');
        yield MenuItem::linkTo(ArtistImportController::class, 'Importer un artiste', 'fa fa-cloud-arrow-down')->setAction('index');
        yield MenuItem::linkTo(ArtistCrudController::class, 'Artistes', 'fa fa-user');
        yield MenuItem::linkTo(AlbumCrudController::class, 'Albums', 'fa fa-compact-disc');
        yield MenuItem::linkTo(TrackCrudController::class, 'Titres', 'fa fa-music');

        yield MenuItem::section();
        yield MenuItem::linkToRoute('Retour au site', 'fa fa-arrow-left', 'app_home');
    }
}

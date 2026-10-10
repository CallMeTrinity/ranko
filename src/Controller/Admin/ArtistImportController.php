<?php

namespace App\Controller\Admin;

use App\Catalog\CatalogImporter;
use App\Catalog\CatalogProviderInterface;
use App\Catalog\Data\ArtistData;
use App\Entity\Artist;
use App\Repository\ExternalReferenceRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[AdminRoute('/import', name: 'artist_import')]
final class ArtistImportController extends AbstractController
{
    public function __construct(
        private readonly CatalogProviderInterface $provider,
        private readonly ExternalReferenceRepository $references,
        private readonly CatalogImporter $importer,
    ) {
    }

    #[AdminRoute('/', name: 'index', options: ['methods' => ['GET']])]
    public function index(#[MapQueryParameter] string $q = ''): Response
    {
        $results = '' !== trim($q) ? $this->provider->searchArtists(trim($q)) : [];

        return $this->render('admin/artist_import.html.twig', [
            'query' => $q,
            'results' => array_map(fn (ArtistData $data): array => [
                'data' => $data,
                'artist' => $this->references->findItem(Artist::class, $this->provider->getName(), $data->externalId),
            ], $results),
        ]);
    }

    #[AdminRoute('/start', name: 'start', options: ['methods' => ['POST']])]
    #[IsCsrfTokenValid('artist-import')]
    public function start(#[MapRequestPayload] ArtistImportRequest $request): Response
    {
        $artist = $this->importer->requestArtistImport($request->externalId);

        $this->addFlash('success', sprintf('Import de "%s" planifié. La discographie arrive en arrière-plan.', $artist->getName()));

        return $this->redirectToRoute('admin_artist_index');
    }
}

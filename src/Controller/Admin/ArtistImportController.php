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
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[IsGranted('ROLE_ADMIN')]
#[AdminRoute('/import', name: 'artist_import')]
final class ArtistImportController extends AbstractController
{
    /**
     * Ces deux appels à MusicBrainz sont synchrones (hors Messenger), donc sans retry :
     * un 503 "server busy" ou une coupure réseau doit donner un message, pas une page 500.
     */
    private const string UNAVAILABLE = 'MusicBrainz ne répond pas pour le moment (serveur surchargé ou injoignable). Réessaie dans quelques secondes.';

    public function __construct(
        private readonly CatalogProviderInterface $provider,
        private readonly ExternalReferenceRepository $references,
        private readonly CatalogImporter $importer,
    ) {
    }

    #[AdminRoute('/', name: 'index', options: ['methods' => ['GET']])]
    public function index(#[MapQueryParameter] string $q = ''): Response
    {
        try {
            $results = '' !== trim($q) ? $this->provider->searchArtists(trim($q)) : [];
        } catch (ServerExceptionInterface|TransportExceptionInterface) {
            $this->addFlash('warning', self::UNAVAILABLE);
            $results = [];
        }

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
        try {
            $artist = $this->importer->requestArtistImport($request->externalId);
        } catch (ServerExceptionInterface|TransportExceptionInterface) {
            $this->addFlash('warning', self::UNAVAILABLE);

            return $this->redirectToRoute('admin_artist_import_index');
        }

        $this->addFlash('success', sprintf('Import de "%s" planifié. La discographie arrive en arrière-plan.', $artist->getName()));

        return $this->redirectToRoute('admin_artist_index');
    }
}

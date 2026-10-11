<?php

namespace App\Controller;

use App\Catalog\Deezer\DeezerPreviewFinder;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Enum\CatalogItemKind;
use App\Repository\ArtistRepository;
use App\Repository\CatalogItemRepository;
use App\Repository\RankingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    public function __construct(
        private readonly CatalogItemRepository $catalog,
    ) {
    }

    #[Route('/artists', name: 'app_artist_index', methods: ['GET'])]
    public function artists(ArtistRepository $artists, #[MapQueryParameter] string $q = ''): Response
    {
        return $this->render('catalog/artists.html.twig', [
            'query' => $q,
            'artists' => $artists->findVisible(trim($q)),
        ]);
    }

    #[Route('/artists/{id<\d+>}', name: 'app_artist_show', methods: ['GET'])]
    public function artist(Artist $artist, RankingRepository $rankings): Response
    {
        if (!$artist->isVisible()) {
            throw $this->createNotFoundException();
        }

        return $this->render('catalog/artist.html.twig', [
            'artist' => $artist,
            'albums' => $this->catalog->findRankable(CatalogItemKind::Album, $artist),
            'community_rankings' => $rankings->findPublicByScope($artist),
        ]);
    }

    #[Route('/albums/{id<\d+>}', name: 'app_album_show', methods: ['GET'])]
    public function album(Album $album, RankingRepository $rankings): Response
    {
        if (!$album->isVisible()) {
            throw $this->createNotFoundException();
        }

        return $this->render('catalog/album.html.twig', [
            'album' => $album,
            'tracks' => $this->catalog->findRankable(CatalogItemKind::Track, $album),
            'community_rankings' => $rankings->findPublicByScope($album),
        ]);
    }

    /**
     * Liste des titres chargée à la demande (turbo-frame) quand on veut se rafraîchir la
     * mémoire sur un album pendant un classement ou un duel.
     */
    #[Route('/albums/{id<\d+>}/tracklist', name: 'app_album_tracklist', methods: ['GET'])]
    public function tracklist(Album $album): Response
    {
        if (!$album->isVisible()) {
            throw $this->createNotFoundException();
        }

        return $this->render('catalog/_tracklist.html.twig', [
            'album' => $album,
            'tracks' => $this->catalog->findRankable(CatalogItemKind::Track, $album),
        ]);
    }

    /**
     * Redirige vers l'extrait de 30 s du titre : c'est la source d'un élément <audio>.
     * L'URL de destination expire vite, la redirection ne doit donc pas être mise en cache.
     */
    #[Route('/tracks/{id<\d+>}/preview', name: 'app_track_preview', methods: ['GET'])]
    public function preview(Track $track, DeezerPreviewFinder $previews): Response
    {
        if (!$track->isVisible()) {
            throw $this->createNotFoundException();
        }

        $url = $previews->findPreviewUrl($track) ?? throw $this->createNotFoundException('Pas d\'extrait pour ce titre.');

        return $this->redirect($url);
    }
}

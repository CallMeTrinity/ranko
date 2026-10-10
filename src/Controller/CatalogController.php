<?php

namespace App\Controller;

use App\Entity\Album;
use App\Entity\Artist;
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
}

<?php

namespace App\Controller;

use App\Enum\CatalogItemKind;
use App\Repository\RankingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class ExploreController extends AbstractController
{
    #[Route('/explore', name: 'app_explore', methods: ['GET'])]
    public function index(RankingRepository $rankings, #[MapQueryParameter] ?CatalogItemKind $kind = null): Response
    {
        $latest = $rankings->findLatestPublic($kind);

        return $this->render('explore/index.html.twig', [
            'kind' => $kind,
            'kinds' => CatalogItemKind::cases(),
            'rankings' => $latest,
            'tops' => $rankings->findTopItems($latest),
        ]);
    }
}

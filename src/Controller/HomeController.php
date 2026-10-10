<?php

namespace App\Controller;

use App\Repository\RankingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(RankingRepository $rankings): Response
    {
        $latest = $rankings->findLatestPublic(limit: 6);

        return $this->render('home/index.html.twig', [
            'rankings' => $latest,
            'tops' => $rankings->findTopItems($latest),
        ]);
    }
}

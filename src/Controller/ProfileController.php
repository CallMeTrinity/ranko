<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\RankingRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    #[Route('/u/{username}', name: 'app_profile', methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['username' => 'username'])] User $user, RankingRepository $rankings): Response
    {
        $public = $rankings->findPublicByOwner($user);

        return $this->render('profile/show.html.twig', [
            'profile' => $user,
            'rankings' => $public,
            'tops' => $rankings->findTopItems($public),
        ]);
    }
}

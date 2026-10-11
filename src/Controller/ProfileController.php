<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\CatalogItemKind;
use App\Form\ProfileFormType;
use App\Profile\ProfileBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ProfileController extends AbstractController
{
    /**
     * Le contenu vient des sections (App\Profile\Section) : en ajouter une ne touche pas ce contrôleur.
     */
    #[Route('/u/{username}', name: 'app_profile', methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['username' => 'username'])] User $user,
        ProfileBuilder $builder,
        #[MapQueryParameter] ?CatalogItemKind $kind = null,
    ): Response {
        $viewer = $this->getUser();

        return $this->render('profile/show.html.twig', [
            'profile' => $user,
            'is_me' => $viewer instanceof User && $viewer->getId() === $user->getId(),
            'blocks' => $builder->build($user, $viewer instanceof User ? $viewer : null, $kind),
        ]);
    }

    #[Route('/profile/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(#[CurrentUser] User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Profil mis à jour.');

            return $this->redirectToRoute('app_profile', ['username' => $user->getUsername()]);
        }

        return $this->render('profile/edit.html.twig', ['form' => $form]);
    }
}

<?php

namespace App\Controller;

use App\Controller\Payload\CreateRankingPayload;
use App\Controller\Payload\ItemPayload;
use App\Controller\Payload\ReorderPayload;
use App\Duel\DuelManager;
use App\Entity\CatalogItem;
use App\Entity\Ranking;
use App\Entity\User;
use App\Ranking\RankingManager;
use App\Repository\CatalogItemRepository;
use App\Repository\RankingRepository;
use App\Security\Voter\RankingVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Toutes les mutations d'un classement partagent le token CSRF "ranking{id}".
 */
#[Route('/rankings')]
final class RankingController extends AbstractController
{
    private const string TOKEN = '"ranking" ~ args["ranking"].getId()';

    public function __construct(
        private readonly RankingManager $manager,
        private readonly CatalogItemRepository $catalog,
    ) {
    }

    #[Route('', name: 'app_ranking_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(#[CurrentUser] User $user, RankingRepository $rankings): Response
    {
        return $this->render('ranking/index.html.twig', [
            'rankings' => $rankings->findByOwner($user),
        ]);
    }

    #[Route('', name: 'app_ranking_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsCsrfTokenValid('ranking-create')]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] CreateRankingPayload $payload): Response
    {
        $scope = null === $payload->scopeId ? null : $this->findItem($payload->scopeId);

        try {
            $ranking = $this->manager->findOrCreate($user, $payload->kind, $scope);
        } catch (\InvalidArgumentException $exception) {
            throw $this->createNotFoundException($exception->getMessage(), $exception);
        }

        return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
    }

    #[Route('/add', name: 'app_ranking_add_item', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsCsrfTokenValid('ranking-add')]
    public function addItem(#[CurrentUser] User $user, #[MapRequestPayload] ItemPayload $payload, Request $request): Response
    {
        $item = $this->findItem($payload->itemId);
        $ranking = $this->manager->addToGlobalRanking($user, $item);

        $this->addFlash('success', sprintf('"%s" ajouté à ton classement "%s".', $item->getName(), $ranking->getTitle()));

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_ranking_show', ['id' => $ranking->getId()]));
    }

    #[Route('/{id<\d+>}', name: 'app_ranking_show', methods: ['GET'])]
    #[IsGranted(RankingVoter::VIEW, 'ranking')]
    public function show(#[MapEntity(expr: 'repository.findWithItems(id)')] Ranking $ranking, DuelManager $duels): Response
    {
        $canEdit = $this->isGranted(RankingVoter::EDIT, $ranking);

        return $this->render('ranking/show.html.twig', [
            'ranking' => $ranking,
            'missing_items' => $canEdit ? $this->manager->findMissingItems($ranking) : [],
            'active_duel' => $canEdit ? $duels->findActive($ranking) : null,
        ]);
    }

    #[Route('/{id<\d+>}/order', name: 'app_ranking_order', methods: ['POST'])]
    #[IsGranted(RankingVoter::EDIT, 'ranking')]
    #[IsCsrfTokenValid(new Expression(self::TOKEN), tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function order(Ranking $ranking, #[MapRequestPayload] ReorderPayload $payload): Response
    {
        try {
            $this->manager->reorder($ranking, $payload->itemIds);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id<\d+>}/visibility', name: 'app_ranking_visibility', methods: ['POST'])]
    #[IsGranted(RankingVoter::EDIT, 'ranking')]
    #[IsCsrfTokenValid(new Expression(self::TOKEN))]
    public function toggleVisibility(Ranking $ranking): Response
    {
        $this->manager->toggleVisibility($ranking);

        return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
    }

    #[Route('/{id<\d+>}/remove', name: 'app_ranking_remove_item', methods: ['POST'])]
    #[IsGranted(RankingVoter::EDIT, 'ranking')]
    #[IsCsrfTokenValid(new Expression(self::TOKEN))]
    public function removeItem(Ranking $ranking, #[MapRequestPayload] ItemPayload $payload): Response
    {
        // Pas de contrôle de visibilité : on doit pouvoir retirer un élément masqué depuis.
        $item = $this->catalog->find($payload->itemId) ?? throw $this->createNotFoundException();
        $this->manager->remove($ranking, $item);

        return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
    }

    #[Route('/{id<\d+>}/append-missing', name: 'app_ranking_append_missing', methods: ['POST'])]
    #[IsGranted(RankingVoter::EDIT, 'ranking')]
    #[IsCsrfTokenValid(new Expression(self::TOKEN))]
    public function appendMissing(Ranking $ranking): Response
    {
        $this->manager->appendMissingItems($ranking);

        return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
    }

    #[Route('/{id<\d+>}/delete', name: 'app_ranking_delete', methods: ['POST'])]
    #[IsGranted(RankingVoter::EDIT, 'ranking')]
    #[IsCsrfTokenValid(new Expression(self::TOKEN))]
    public function delete(Ranking $ranking): Response
    {
        $this->manager->delete($ranking);
        $this->addFlash('success', 'Classement supprimé.');

        return $this->redirectToRoute('app_ranking_index');
    }

    private function findItem(int $id): CatalogItem
    {
        $item = $this->catalog->find($id);
        if (null === $item || !$item->isVisible()) {
            throw $this->createNotFoundException();
        }

        return $item;
    }
}

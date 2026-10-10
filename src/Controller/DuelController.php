<?php

namespace App\Controller;

use App\Controller\Payload\AnswerPayload;
use App\Controller\Payload\ItemPayload;
use App\Duel\DuelManager;
use App\Entity\CatalogItem;
use App\Entity\Duel;
use App\Entity\Ranking;
use App\Ranking\RankingManager;
use App\Repository\CatalogItemRepository;
use App\Security\Voter\RankingVoter;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le duel appartient au classement : mêmes droits (EDIT) et même token CSRF.
 */
#[Route('/rankings/{id<\d+>}/duel')]
#[IsGranted(RankingVoter::EDIT, 'ranking')]
final class DuelController extends AbstractController
{
    private const string TOKEN = '"ranking" ~ args["ranking"].getId()';

    public function __construct(
        private readonly DuelManager $duels,
        private readonly CatalogItemRepository $catalog,
    ) {
    }

    #[Route('', name: 'app_duel_show', methods: ['GET'])]
    public function show(Ranking $ranking): Response
    {
        $duel = $this->duels->findActive($ranking);
        if (null === $duel) {
            return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
        }

        $algorithm = $duel->getAlgorithm();
        [$leftId, $rightId] = $algorithm->currentPair() ?? throw new \LogicException('Un duel actif a toujours une question en cours.');
        $left = $this->catalog->find($leftId);
        $right = $this->catalog->find($rightId);

        if (null === $left || null === $right) {
            // Un élément a disparu du catalogue en cours de route : on ne peut pas continuer.
            $this->duels->abandon($duel);
            $this->addFlash('warning', 'Un élément du duel a été supprimé du catalogue, le duel est annulé.');

            return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
        }

        return $this->render('duel/show.html.twig', [
            'ranking' => $ranking,
            'duel' => $duel,
            'left' => $left,
            'right' => $right,
            'question' => $algorithm->comparisons() + 1,
            'max_remaining' => max(1, $algorithm->maxComparisons() - $algorithm->comparisons()),
            'progress' => $algorithm->maxComparisons() > 0 ? $algorithm->comparisons() / $algorithm->maxComparisons() : 0,
        ]);
    }

    #[Route('/sort', name: 'app_duel_start_sort', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::TOKEN))]
    public function startSort(Ranking $ranking): Response
    {
        try {
            $this->duels->startSort($ranking);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('warning', $exception->getMessage());

            return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
        }

        return $this->redirectToRoute('app_duel_show', ['id' => $ranking->getId()]);
    }

    #[Route('/insert-missing', name: 'app_duel_insert_missing', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::TOKEN))]
    public function insertMissing(Ranking $ranking, RankingManager $rankings): Response
    {
        return $this->startInsertion($ranking, $rankings->findMissingItems($ranking));
    }

    #[Route('/place', name: 'app_duel_place_item', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::TOKEN))]
    public function placeItem(Ranking $ranking, #[MapRequestPayload] ItemPayload $payload): Response
    {
        $item = $this->catalog->find($payload->itemId) ?? throw $this->createNotFoundException();

        return $this->startInsertion($ranking, [$item]);
    }

    #[Route('/answer', name: 'app_duel_answer', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::TOKEN))]
    public function answer(Ranking $ranking, #[MapRequestPayload] AnswerPayload $payload): Response
    {
        $duel = $this->duels->findActive($ranking);
        if (null === $duel) {
            return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
        }

        try {
            $finished = $this->duels->answer($duel, $payload->leftId, $payload->rightId, $payload->winnerId);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Ce choix ne fait pas partie de la question.');
        } catch (OptimisticLockException) {
            // Une autre réponse est passée juste avant : on réaffiche la question en cours.
            return $this->redirectToRoute('app_duel_show', ['id' => $ranking->getId()]);
        }

        if ($finished) {
            $this->addFlash('success', 'Duel terminé, le classement est à jour.');

            return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
        }

        return $this->redirectToRoute('app_duel_show', ['id' => $ranking->getId()]);
    }

    #[Route('/undo', name: 'app_duel_undo', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::TOKEN))]
    public function undo(Ranking $ranking): Response
    {
        $duel = $this->duels->findActive($ranking);
        if (null !== $duel) {
            $this->duels->undo($duel);
        }

        return $this->redirectToRoute('app_duel_show', ['id' => $ranking->getId()]);
    }

    #[Route('/abandon', name: 'app_duel_abandon', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression(self::TOKEN))]
    public function abandon(Ranking $ranking): Response
    {
        $duel = $this->duels->findActive($ranking);
        if (null !== $duel) {
            $this->duels->abandon($duel);
        }

        return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
    }

    /**
     * @param list<CatalogItem> $items
     */
    private function startInsertion(Ranking $ranking, array $items): Response
    {
        try {
            $duel = $this->duels->startInsertion($ranking, $items);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('warning', $exception->getMessage());

            return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
        }

        return $this->redirectToRoute($this->isActive($duel) ? 'app_duel_show' : 'app_ranking_show', ['id' => $ranking->getId()]);
    }

    private function isActive(Duel $duel): bool
    {
        return !$duel->getAlgorithm()->isFinished();
    }
}

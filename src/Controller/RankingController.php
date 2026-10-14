<?php

namespace App\Controller;

use App\Controller\Payload\CreateRankingPayload;
use App\Controller\Payload\ItemPayload;
use App\Controller\Payload\ReorderPayload;
use App\Duel\DuelManager;
use App\Entity\Artist;
use App\Entity\CatalogItem;
use App\Entity\Ranking;
use App\Entity\User;
use App\Enum\CatalogItemKind;
use App\Form\CustomRankingFormType;
use App\Form\Data\CustomRankingData;
use App\Form\Data\RenameRankingData;
use App\Form\RenameRankingFormType;
use App\Ranking\Comparison\RankingComparator;
use App\Ranking\RankingManager;
use App\Repository\CatalogItemRepository;
use App\Repository\RankingRepository;
use App\Repository\SpotifyAccountRepository;
use App\Repository\SpotifyPlaylistRepository;
use App\Security\Voter\RankingVoter;
use App\Spotify\SpotifyAccess;
use App\Spotify\SpotifyPlaylistSync;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
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

    /** Éléments affichés à la fois dans le réservoir d'une liste personnalisée. */
    private const int POOL_PAGE = 30;
    private const int POOL_MAX = 300;

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

    /**
     * Liste personnalisée, éventuellement limitée à un artiste ou un album (?scope=id).
     */
    #[Route('/new', name: 'app_ranking_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(#[CurrentUser] User $user, Request $request, #[MapQueryParameter] ?int $scope = null): Response
    {
        $scopeItem = null === $scope ? null : $this->findItem($scope);
        $kinds = null === $scopeItem ? CatalogItemKind::cases() : $scopeItem->getKind()->rankableKinds();
        if ([] === $kinds) {
            throw $this->createNotFoundException('Rien à classer dans ce périmètre.');
        }

        $data = new CustomRankingData();
        $data->kind = $kinds[0];
        $form = $this->createForm(CustomRankingFormType::class, $data, ['kinds' => $kinds]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ranking = $this->manager->createCustom($user, (string) $data->name, $data->kind ?? $kinds[0], $data->maxSize, $scopeItem);

            return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
        }

        return $this->render('ranking/new.html.twig', [
            'form' => $form,
            'scope' => $scopeItem,
        ]);
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
    public function show(
        #[MapEntity(expr: 'repository.findWithItems(id)')] Ranking $ranking,
        DuelManager $duels,
        RankingRepository $rankings,
        SpotifyPlaylistSync $spotifySync,
        SpotifyPlaylistRepository $spotifyPlaylists,
        SpotifyAccountRepository $spotifyAccounts,
    ): Response {
        $canEdit = $this->isGranted(RankingVoter::EDIT, $ranking);
        $viewer = $this->getUser();
        $withSpotify = $canEdit && $viewer instanceof User && $this->isGranted(SpotifyAccess::ROLE) && $spotifySync->supports($ranking);

        return $this->render('ranking/show.html.twig', [
            'ranking' => $ranking,
            'missing_items' => $canEdit ? $this->manager->findMissingItems($ranking) : [],
            'active_duel' => $canEdit ? $duels->findActive($ranking) : null,
            // Un visiteur connecté peut confronter ce classement aux siens du même type.
            'comparable' => $viewer instanceof User && !$canEdit ? $rankings->findComparable($viewer, $ranking) : [],
            // Rendu dans la page plutôt que chargé par la frame : voir ranking/_pool.html.twig.
            'pool' => $canEdit && $ranking->isCustom() ? $this->poolContent($ranking, '', null, self::POOL_PAGE) : null,
            'pool_filters' => $canEdit && $ranking->isCustom() ? $this->poolFilters($ranking) : [],
            'spotify' => $withSpotify ? [
                'connected' => null !== $spotifyAccounts->findOneByUser($viewer),
                'playlist' => $spotifyPlaylists->findOneByRanking($ranking),
            ] : null,
        ]);
    }

    /**
     * Réservoir d'une liste personnalisée : les éléments qu'on peut encore y ajouter,
     * éventuellement restreints à un artiste ou un album (?filter=id).
     */
    #[Route('/{id<\d+>}/pool', name: 'app_ranking_pool', methods: ['GET'])]
    #[IsGranted(RankingVoter::EDIT, 'ranking')]
    public function pool(
        #[MapEntity(expr: 'repository.findWithItems(id)')] Ranking $ranking,
        #[MapQueryParameter] string $q = '',
        // Vide quand on revient à "Tous" dans la liste déroulante.
        #[MapQueryParameter(flags: \FILTER_NULL_ON_FAILURE)] ?int $filter = null,
        #[MapQueryParameter] int $limit = self::POOL_PAGE,
    ): Response {
        if (!$ranking->isCustom()) {
            throw $this->createNotFoundException();
        }

        $filterItem = null === $filter ? null : $this->findItem($filter);
        // Un filtre est un périmètre plus fin que celui de la liste, jamais un autre.
        if (null !== $filterItem && (!\in_array($filterItem->getKind(), $ranking->getItemKind()->allowedScopeKinds(), true)
            || (null !== $ranking->getScope() && !$filterItem->isWithin($ranking->getScope())))) {
            throw $this->createNotFoundException();
        }

        return $this->render('ranking/_pool.html.twig', [
            'ranking' => $ranking,
            'pool' => $this->poolContent($ranking, trim($q), $filterItem, $limit),
        ]);
    }

    /**
     * Ajoute un élément du réservoir, à la fin ou à la position où on l'a déposé.
     */
    #[Route('/{id<\d+>}/add', name: 'app_ranking_add', methods: ['POST'])]
    #[IsGranted(RankingVoter::EDIT, 'ranking')]
    #[IsCsrfTokenValid(new Expression(self::TOKEN))]
    public function add(Ranking $ranking, #[MapRequestPayload] ItemPayload $payload): Response
    {
        $item = $this->findItem($payload->itemId);

        try {
            $this->manager->addItem($ranking, $item, $payload->position);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
    }

    #[Route('/{id<\d+>}/compare/{otherId<\d+>}', name: 'app_ranking_compare', methods: ['GET'])]
    #[IsGranted(RankingVoter::VIEW, 'ranking')]
    #[IsGranted(RankingVoter::VIEW, 'other')]
    public function compare(
        #[MapEntity(expr: 'repository.findWithItems(id)')] Ranking $ranking,
        #[MapEntity(expr: 'repository.findWithItems(otherId)')] Ranking $other,
        RankingComparator $comparator,
    ): Response {
        if ($ranking->getItemKind() !== $other->getItemKind()) {
            throw $this->createNotFoundException('On ne compare que des classements du même type.');
        }

        return $this->render('ranking/compare.html.twig', [
            'first' => $ranking,
            'second' => $other,
            ...$comparator->compare($ranking, $other),
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

    #[Route('/{id<\d+>}/rename', name: 'app_ranking_rename', methods: ['GET', 'POST'])]
    #[IsGranted(RankingVoter::EDIT, 'ranking')]
    public function rename(Ranking $ranking, Request $request): Response
    {
        if (!$ranking->isCustom()) {
            throw $this->createNotFoundException('Seule une liste personnalisée se renomme.');
        }

        $data = new RenameRankingData($ranking->getName());
        $form = $this->createForm(RenameRankingFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->rename($ranking, (string) $data->name);

            return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
        }

        return $this->render('ranking/rename.html.twig', [
            'ranking' => $ranking,
            'form' => $form,
        ]);
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

    /**
     * @return array{items: list<CatalogItem>, query: string, filter: ?CatalogItem, has_more: bool, next_limit: int}
     */
    private function poolContent(Ranking $ranking, string $query, ?CatalogItem $filter, int $limit): array
    {
        $limit = max(1, min($limit, self::POOL_MAX));
        $excludedIds = array_map(static fn (CatalogItem $item): int => (int) $item->getId(), $ranking->getItems());
        // Un élément de plus que demandé : sa présence dit s'il reste de quoi afficher.
        $items = $this->catalog->searchRankable($ranking->getItemKind(), $filter ?? $ranking->getScope(), $query, $excludedIds, $limit + 1);

        return [
            'items' => \array_slice($items, 0, $limit),
            'query' => $query,
            'filter' => $filter,
            'has_more' => \count($items) > $limit && $limit < self::POOL_MAX,
            'next_limit' => min($limit + self::POOL_PAGE, self::POOL_MAX),
        ];
    }

    /**
     * Choix du filtre du réservoir : les artistes pour une liste d'albums, les albums
     * de l'artiste pour une liste de titres limitée à un artiste, et pour une liste de
     * titres sans périmètre, chaque artiste suivi de ses albums.
     *
     * @return list<array{label: ?string, items: list<CatalogItem>}>
     */
    private function poolFilters(Ranking $ranking): array
    {
        $scope = $ranking->getScope();

        return match ($ranking->getItemKind()) {
            CatalogItemKind::Artist => [],
            CatalogItemKind::Album => null === $scope ? [['label' => null, 'items' => $this->catalog->findRankable(CatalogItemKind::Artist)]] : [],
            CatalogItemKind::Track => match (true) {
                $scope instanceof Artist => [['label' => null, 'items' => $this->catalog->findRankable(CatalogItemKind::Album, $scope)]],
                null === $scope => $this->albumsByArtist(),
                default => [],
            },
        };
    }

    /**
     * @return list<array{label: string, items: list<CatalogItem>}>
     */
    private function albumsByArtist(): array
    {
        $albums = $this->catalog->findRankable(CatalogItemKind::Album);

        return array_map(static fn (CatalogItem $artist): array => [
            'label' => $artist->getName(),
            'items' => [$artist, ...array_filter($albums, static fn (CatalogItem $album): bool => $album->getParent() === $artist)],
        ], $this->catalog->findRankable(CatalogItemKind::Artist));
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

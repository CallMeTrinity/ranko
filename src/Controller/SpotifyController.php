<?php

namespace App\Controller;

use App\Entity\Ranking;
use App\Entity\User;
use App\Security\Voter\RankingVoter;
use App\Spotify\SpotifyAccess;
use App\Spotify\SpotifyApi;
use App\Spotify\SpotifyApiException;
use App\Spotify\SpotifyConnector;
use App\Spotify\SpotifyPlaylistSync;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Connexion d'un compte Spotify et synchro d'un classement vers une playlist. Réservé
 * aux utilisateurs à qui l'admin a donné une place (SpotifyAccess).
 */
#[IsGranted(SpotifyAccess::ROLE)]
final class SpotifyController extends AbstractController
{
    /** État du flux OAuth en session : protège le retour de Spotify contre le CSRF. */
    private const string SESSION_KEY = 'spotify_oauth';

    public function __construct(
        private readonly SpotifyApi $api,
        private readonly SpotifyConnector $connector,
    ) {
    }

    /**
     * Part vers la page de consentement Spotify. ?ranking=id : on y revient ensuite.
     */
    #[Route('/spotify/connect', name: 'app_spotify_connect', methods: ['GET'])]
    public function connect(Request $request, #[MapQueryParameter] ?int $ranking = null): Response
    {
        if (!$this->api->isConfigured()) {
            $this->addFlash('error', 'Spotify n\'est pas configuré sur ce serveur.');

            return $this->redirectToRoute('app_profile_edit');
        }

        // Spotify n'accepte pas "localhost" comme redirect URI, et l'URL de retour reprend
        // l'hôte de la requête. Basculer seulement le retour sur 127.0.0.1 ne suffirait pas :
        // la session (state OAuth, connexion) est liée à l'hôte et serait perdue.
        if ('localhost' === $request->getHost()) {
            $this->addFlash('error', sprintf('Spotify refuse "localhost" : ouvre Ranko sur http://127.0.0.1:%d et reconnecte-toi.', $request->getPort()));

            return null === $ranking
                ? $this->redirectToRoute('app_profile_edit')
                : $this->redirectToRoute('app_ranking_show', ['id' => $ranking]);
        }

        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::SESSION_KEY, ['state' => $state, 'ranking' => $ranking]);

        return $this->redirect($this->api->authorizeUrl($this->callbackUrl(), $state));
    }

    #[Route('/spotify/callback', name: 'app_spotify_callback', methods: ['GET'])]
    public function callback(
        #[CurrentUser] User $user,
        Request $request,
        LoggerInterface $logger,
        #[MapQueryParameter] string $state = '',
        #[MapQueryParameter] string $code = '',
        #[MapQueryParameter] string $error = '',
    ): Response {
        /** @var array{state: string, ranking: ?int}|null $pending */
        $pending = $request->getSession()->remove(self::SESSION_KEY);
        $target = null === ($pending['ranking'] ?? null)
            ? $this->redirectToRoute('app_profile_edit')
            : $this->redirectToRoute('app_ranking_show', ['id' => $pending['ranking']]);

        if (null === $pending || '' === $state || !hash_equals($pending['state'], $state)) {
            $this->addFlash('error', 'La connexion à Spotify a expiré : recommence.');

            return $target;
        }

        if ('' !== $error || '' === $code) {
            $this->addFlash('error', 'Connexion à Spotify annulée.');

            return $target;
        }

        try {
            $account = $this->connector->connect($user, $code, $this->callbackUrl());
        } catch (SpotifyApiException $exception) {
            $logger->warning('Connexion Spotify refusée : {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
            $this->addFlash('error', 403 === $exception->statusCode
                ? 'Ce compte Spotify n\'est pas autorisé sur Ranko. Donne à l\'admin l\'email de ton compte Spotify.'
                : 'Spotify n\'a pas accepté la connexion : réessaie dans un moment.');

            return $target;
        }

        $this->addFlash('success', sprintf('Compte Spotify "%s" connecté.', $account->getLabel()));

        return $target;
    }

    #[Route('/spotify/disconnect', name: 'app_spotify_disconnect', methods: ['POST'])]
    #[IsCsrfTokenValid('spotify-disconnect')]
    public function disconnect(#[CurrentUser] User $user): Response
    {
        $this->connector->disconnect($user);
        $this->addFlash('success', 'Compte Spotify déconnecté. Tes playlists restent dans Spotify.');

        return $this->redirectToRoute('app_profile_edit');
    }

    /**
     * Même token CSRF que les autres actions du classement (RankingController).
     */
    #[Route('/rankings/{id<\d+>}/spotify', name: 'app_ranking_spotify', methods: ['POST'])]
    #[IsGranted(RankingVoter::EDIT, 'ranking')]
    #[IsCsrfTokenValid(new Expression('"ranking" ~ args["ranking"].getId()'))]
    public function sync(Ranking $ranking, SpotifyPlaylistSync $sync): Response
    {
        if (!$sync->supports($ranking)) {
            throw $this->createNotFoundException('Seul un classement de titres devient une playlist.');
        }

        $sync->request($ranking);
        $this->addFlash('success', 'Synchro Spotify demandée : la playlist sera à jour dans quelques minutes.');

        return $this->redirectToRoute('app_ranking_show', ['id' => $ranking->getId()]);
    }

    /**
     * Doit être déclarée à l'identique dans le dashboard Spotify. En local, Spotify refuse
     * "localhost" : ouvrir l'app sur http://127.0.0.1:8000.
     */
    private function callbackUrl(): string
    {
        return $this->generateUrl('app_spotify_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}

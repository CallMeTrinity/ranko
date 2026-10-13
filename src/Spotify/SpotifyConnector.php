<?php

namespace App\Spotify;

use App\Entity\SpotifyAccount;
use App\Entity\User;
use App\Repository\SpotifyAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Connexion d'un compte Spotify (flux OAuth Authorization Code) et tokens toujours valides.
 */
final class SpotifyConnector
{
    public function __construct(
        private readonly SpotifyApi $api,
        private readonly SpotifyAccountRepository $accounts,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Termine le flux OAuth : échange le code, puis lit le compte. Si ce compte n'est pas
     * dans la liste blanche de l'app, Spotify donne quand même un token mais répond 403
     * à tout appel : on ne l'enregistre pas.
     *
     * @throws SpotifyApiException
     */
    public function connect(User $user, string $code, string $redirectUri): SpotifyAccount
    {
        $now = $this->clock->now();
        $tokens = $this->api->exchangeCode($code, $redirectUri);
        $me = $this->api->me($tokens->accessToken);
        $refreshToken = $tokens->refreshToken ?? throw new SpotifyApiException('Spotify n\'a pas donné de refresh token.');

        $account = $this->accounts->findOneByUser($user);
        if (null === $account) {
            $account = new SpotifyAccount($user, $me['id'], $me['display_name'], $tokens->accessToken, $refreshToken, $tokens->expiresAt($now));
            $this->entityManager->persist($account);
        } else {
            $account->reconnect($me['id'], $me['display_name'], $tokens->accessToken, $refreshToken, $tokens->expiresAt($now));
        }

        $this->entityManager->flush();

        return $account;
    }

    public function disconnect(User $user): void
    {
        $account = $this->accounts->findOneByUser($user);
        if (null !== $account) {
            $this->entityManager->remove($account);
            $this->entityManager->flush();
        }
    }

    /**
     * Un access token vit une heure : on le rafraîchit juste avant qu'il expire.
     *
     * @throws SpotifyApiException
     */
    public function accessToken(SpotifyAccount $account): string
    {
        $now = $this->clock->now();

        if ($account->isExpired($now)) {
            $tokens = $this->api->refreshTokens($account->getRefreshToken());
            $account->updateTokens($tokens->accessToken, $tokens->refreshToken, $tokens->expiresAt($now));
            $this->entityManager->flush();
        }

        return $account->getAccessToken();
    }
}

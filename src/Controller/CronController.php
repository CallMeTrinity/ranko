<?php

namespace App\Controller;

use App\Messenger\QueueRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Points d'entrée du planificateur de tâches Infomaniak, qui ne sait qu'appeler une URL.
 *
 * Route publique, protégée par un secret partagé passé en ?token=. Garder l'URL
 * configurée privée ; changer APP_CRON_SECRET si elle fuite. Secret vide = route
 * désactivée.
 */
final class CronController extends AbstractController
{
    #[Route('/cron/messenger', name: 'app_cron_messenger', methods: ['GET'])]
    public function messenger(
        QueueRunner $runner,
        LockFactory $lockFactory,
        #[Autowire('%env(APP_CRON_SECRET)%')] string $secret,
        #[Autowire('%env(int:CRON_TIME_LIMIT)%')] int $timeLimit,
        #[MapQueryParameter] string $token = '',
    ): JsonResponse {
        if ('' === $secret || !hash_equals($secret, $token)) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        // Si un appel dure plus que l'intervalle du planificateur, le suivant ne lance
        // pas un second worker en parallèle. TTL au-delà du budget : le verrou expire
        // même si le processus est tué en cours de route.
        $lock = $lockFactory->createLock('cron-messenger', ttl: $timeLimit + 60);
        if (!$lock->acquire()) {
            return $this->json(['status' => 'busy']);
        }

        // Le planificateur peut couper la connexion avant la fin : on termine quand même
        // le message en cours plutôt que de le laisser à moitié traité.
        ignore_user_abort(true);
        set_time_limit($timeLimit + 30);

        try {
            return $this->json(['status' => 'ok', ...$runner->run($timeLimit)]);
        } finally {
            $lock->release();
        }
    }
}

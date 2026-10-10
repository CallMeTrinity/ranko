<?php

namespace App\Controller;

use App\Messenger\QueueRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
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
        #[Autowire('%env(APP_CRON_SECRET)%')] string $secret,
        #[MapQueryParameter] string $token = '',
    ): JsonResponse {
        if ('' === $secret || !hash_equals($secret, $token)) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $result = $runner->run();

        return $this->json(null === $result ? ['status' => 'busy'] : ['status' => 'ok', ...$result]);
    }
}

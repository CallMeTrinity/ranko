<?php

namespace App\Messenger;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Consomme la file "async" pendant un temps limité, depuis une requête HTTP.
 *
 * Sur l'hébergement mutualisé, pas de worker permanent ni de cron shell : le
 * planificateur d'Infomaniak appelle une URL. On lance alors le vrai messenger:consume
 * (retries, transport "failed", reset des services entre deux messages) dans le
 * processus de la requête, et on s'arrête au premier des deux : budget de temps
 * écoulé ou file vide. L'appel suivant reprend où on en était.
 */
final class QueueRunner
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly EventDispatcherInterface $dispatcher,
        #[Autowire(service: 'messenger.transport.async')]
        private readonly TransportInterface $transport,
    ) {
    }

    /**
     * @return array{handled: int, retried: int, failed: int, remaining: int|null}
     */
    public function run(int $timeLimit): array
    {
        $handled = $retried = $failed = 0;
        $countHandled = static function () use (&$handled): void { ++$handled; };
        // Un échec est soit rejoué plus tard (retry), soit définitif (transport "failed").
        $countFailed = static function (WorkerMessageFailedEvent $event) use (&$retried, &$failed): void {
            $event->willRetry() ? ++$retried : ++$failed;
        };
        // Sans ça, le worker dormirait jusqu'à la fin du budget une fois la file vide.
        $stopWhenIdle = static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        };

        $this->dispatcher->addListener(WorkerMessageHandledEvent::class, $countHandled);
        $this->dispatcher->addListener(WorkerMessageFailedEvent::class, $countFailed);
        $this->dispatcher->addListener(WorkerRunningEvent::class, $stopWhenIdle);

        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);
            $application->run(new ArrayInput([
                'command' => 'messenger:consume',
                'receivers' => ['async'],
                '--time-limit' => $timeLimit,
                '--memory-limit' => '128M',
            ]), new BufferedOutput());
        } finally {
            $this->dispatcher->removeListener(WorkerMessageHandledEvent::class, $countHandled);
            $this->dispatcher->removeListener(WorkerMessageFailedEvent::class, $countFailed);
            $this->dispatcher->removeListener(WorkerRunningEvent::class, $stopWhenIdle);
        }

        return [
            'handled' => $handled,
            'retried' => $retried,
            'failed' => $failed,
            'remaining' => $this->transport instanceof MessageCountAwareInterface ? $this->transport->getMessageCount() : null,
        ];
    }
}

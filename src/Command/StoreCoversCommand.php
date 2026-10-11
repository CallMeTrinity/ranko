<?php

namespace App\Command;

use App\Catalog\CatalogImporter;
use App\Repository\AlbumRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:catalog:store-covers', description: 'Copie en local les pochettes qui ne le sont pas encore')]
final class StoreCoversCommand
{
    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly CatalogImporter $importer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $albums = $this->albums->findWithRemoteCoverOnly();
        $failed = 0;

        foreach ($io->progressIterate($albums) as $album) {
            $this->importer->storeCover($album);
            // Flush à chaque album : une interruption ne fait pas perdre les précédents.
            $this->entityManager->flush();

            if (null === $album->getCoverPath()) {
                ++$failed;
            }
        }

        if ($failed > 0) {
            $io->warning(sprintf('%d pochette(s) sur %d injoignable(s). Relancer la commande plus tard.', $failed, count($albums)));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d pochette(s) copiée(s).', count($albums)));

        return Command::SUCCESS;
    }
}

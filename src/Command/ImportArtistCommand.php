<?php

namespace App\Command;

use App\Catalog\CatalogImporter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:catalog:import-artist', description: 'Met en file l\'import de la discographie d\'un artiste (identifiant MusicBrainz)')]
final class ImportArtistCommand
{
    public function __construct(
        private readonly CatalogImporter $importer,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Identifiant MusicBrainz de l\'artiste')] string $externalId): int
    {
        $artist = $this->importer->requestArtistImport($externalId);

        $io->success(sprintf('Import de "%s" planifié. Lancer messenger:consume async pour le traiter.', $artist->getName()));

        return Command::SUCCESS;
    }
}

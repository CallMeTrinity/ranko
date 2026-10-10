<?php

namespace App\MessageHandler;

use App\Catalog\CatalogImporter;
use App\Catalog\Exception\ItemNotFoundException;
use App\Message\ImportArtistDiscography;
use App\Repository\ArtistRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class ImportArtistDiscographyHandler
{
    public function __construct(
        private readonly ArtistRepository $artists,
        private readonly CatalogImporter $importer,
    ) {
    }

    public function __invoke(ImportArtistDiscography $message): void
    {
        $artist = $this->artists->find($message->artistId);
        if (null === $artist) {
            return;
        }

        try {
            $this->importer->importDiscography($artist);
        } catch (ItemNotFoundException $exception) {
            // Inutile de réessayer : l'artiste a été supprimé ou fusionné chez la source.
            throw new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        }
    }
}

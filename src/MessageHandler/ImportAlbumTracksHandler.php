<?php

namespace App\MessageHandler;

use App\Catalog\CatalogImporter;
use App\Catalog\Exception\ItemNotFoundException;
use App\Message\ImportAlbumTracks;
use App\Repository\AlbumRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class ImportAlbumTracksHandler
{
    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly CatalogImporter $importer,
    ) {
    }

    public function __invoke(ImportAlbumTracks $message): void
    {
        $album = $this->albums->find($message->albumId);
        if (null === $album) {
            return;
        }

        try {
            $this->importer->importTracks($album);
        } catch (ItemNotFoundException $exception) {
            throw new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        }
    }
}

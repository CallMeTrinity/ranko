<?php

namespace App\Media;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Copie locale d'images distantes, que LiipImagine redimensionne ensuite.
 *
 * Servir une image depuis son hôte d'origine ne permet ni de la redimensionner, ni de
 * maîtriser son cache : Cover Art Archive, par exemple, passe par trois redirections et
 * ne renvoie aucun en-tête de cache.
 *
 * Le nom du fichier dérive de l'URL source : une nouvelle image donne un nouveau chemin,
 * ce qui permet de mettre les variantes en cache navigateur sans date d'expiration.
 */
final class ImageStore
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Filesystem $filesystem,
        #[Autowire('%app.media_dir%')]
        private readonly string $mediaDir,
    ) {
    }

    /**
     * Télécharge l'image dans $directory si elle n'y est pas déjà.
     *
     * @return string|null chemin relatif au dossier des médias, à passer à imagine_filter ;
     *                     null si l'image est injoignable ou si la réponse n'est pas une image
     */
    public function store(string $url, string $directory): ?string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, \PHP_URL_PATH), \PATHINFO_EXTENSION)) ?: 'jpg';
        $path = sprintf('%s/%s.%s', $directory, hash('xxh128', $url), $extension);

        if ($this->filesystem->exists($this->mediaDir.'/'.$path)) {
            return $path;
        }

        try {
            $content = $this->httpClient->request('GET', $url, ['timeout' => 15])->getContent();
        } catch (HttpClientExceptionInterface) {
            return null;
        }

        // Un miroir en panne peut répondre 200 avec une page HTML.
        if (false === @getimagesizefromstring($content)) {
            return null;
        }

        $this->filesystem->dumpFile($this->mediaDir.'/'.$path, $content);

        return $path;
    }
}

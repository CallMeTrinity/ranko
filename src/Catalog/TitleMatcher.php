<?php

namespace App\Catalog;

use function Symfony\Component\String\u;

/**
 * Comparaison de noms entre MusicBrainz et une plateforme d'écoute (Deezer, Spotify),
 * qui n'écrivent pas les titres tout à fait pareil.
 */
final class TitleMatcher
{
    /** Titre identique. */
    public const int EXACT = 0;
    /** Titre identique une fois les parenthèses retirées. */
    public const int WITHOUT_PARENTHESES = 2;

    /**
     * Compare sans tenir compte de la casse, des accents ni de la ponctuation :
     * MusicBrainz écrit "’" là où Deezer écrit "'".
     */
    public static function same(string $a, string $b): bool
    {
        $normalize = static fn (string $value): string => u($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();

        return '' !== $normalize($a) && $normalize($a) === $normalize($b);
    }

    /**
     * Un titre identique passe avant un titre identique une fois les parenthèses retirées
     * ("Stop Whispering (US version)" chez MusicBrainz, "Stop Whispering" chez Deezer).
     *
     * @param list<string> $candidates titres du résultat (titre et titre court chez Deezer)
     *
     * @return self::EXACT|self::WITHOUT_PARENTHESES|null null si aucun ne correspond
     */
    public static function titleScore(array $candidates, string $title): ?int
    {
        foreach ([self::EXACT => static fn (string $value): string => $value, self::WITHOUT_PARENTHESES => self::withoutParentheses(...)] as $score => $simplify) {
            foreach ($candidates as $candidate) {
                if (self::same($simplify($candidate), $simplify($title))) {
                    return $score;
                }
            }
        }

        return null;
    }

    public static function withoutParentheses(string $title): string
    {
        return (string) preg_replace('/\s*[(\[][^)\]]*[)\]]/', '', $title);
    }

    /**
     * Les moteurs de recherche des plateformes s'étouffent sur les parenthèses (Deezer ne
     * renvoie plus rien). On retire donc la partie entre parenthèses, sauf si c'est tout le
     * titre ("(Nice Dream)").
     */
    public static function searchable(string $title): string
    {
        $withoutParentheses = trim(self::withoutParentheses($title));

        return '' !== $withoutParentheses ? $withoutParentheses : trim(strtr($title, '()[]', '    '));
    }
}

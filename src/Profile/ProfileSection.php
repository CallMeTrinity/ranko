<?php

namespace App\Profile;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Bloc de la page profil. Ajouter une section = une classe qui implémente cette
 * interface et un partial Twig ; l'ordre se règle avec #[AsTaggedItem(priority: ...)],
 * la plus haute priorité en premier.
 */
#[AutoconfigureTag]
interface ProfileSection
{
    /**
     * Null quand la section n'a rien à montrer pour ce profil et ce visiteur.
     */
    public function build(ProfileContext $context): ?ProfileBlock;
}

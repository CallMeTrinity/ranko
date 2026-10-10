<?php

namespace App\Security\Voter;

use App\Entity\Ranking;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Ranking>
 */
final class RankingVoter extends Voter
{
    public const string VIEW = 'RANKING_VIEW';
    public const string EDIT = 'RANKING_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof Ranking;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $isOwner = $user instanceof User && $user->getId() === $subject->getOwner()->getId();

        return match ($attribute) {
            self::VIEW => $isOwner || $subject->isPublic(),
            default => $isOwner,
        };
    }
}

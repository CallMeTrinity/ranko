<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Ask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:user:create', description: 'Crée un utilisateur, admin avec --admin')]
final class CreateUserCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email du compte')]
        #[Ask('Email ?')]
        string $email,
        #[Argument('Mot de passe (demandé de façon masquée si absent)')]
        #[Ask('Mot de passe ?', hidden: true)]
        string $password,
        #[Argument('Pseudo public')]
        #[Ask('Pseudo ?')]
        string $username,
        #[Option('Donne le rôle ROLE_ADMIN')]
        bool $admin = false,
    ): int {
        $user = new User($email, $username);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        if ($admin) {
            $user->setRoles(['ROLE_ADMIN']);
        }

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(sprintf('%s : %s', $violation->getPropertyPath(), $violation->getMessage()));
            }

            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Utilisateur %s créé%s.', $email, $admin ? ' (admin)' : ''));

        return Command::SUCCESS;
    }
}

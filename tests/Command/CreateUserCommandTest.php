<?php

namespace App\Tests\Command;

use App\Entity\User;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateUserCommandTest extends KernelTestCase
{
    use CreatesEntities;

    public function testCreatesAdmin(): void
    {
        $tester = $this->tester();
        $tester->execute(['email' => 'root@example.com', 'password' => 'secret123', 'username' => 'root', '--admin' => true]);

        $tester->assertCommandIsSuccessful();
        $user = self::em()->getRepository(User::class)->findOneBy(['email' => 'root@example.com']);
        self::assertNotNull($user);
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertNotSame('secret123', $user->getPassword(), 'Le mot de passe est haché');
    }

    public function testRejectsDuplicateUsername(): void
    {
        self::createUser('root');

        $tester = $this->tester();
        $tester->execute(['email' => 'autre@example.com', 'password' => 'secret123', 'username' => 'root']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Ce pseudo est déjà pris', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('app:user:create'));
    }
}

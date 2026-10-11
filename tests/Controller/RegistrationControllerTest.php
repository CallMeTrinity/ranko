<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    use CreatesEntities;

    public function testRegisterLogsTheUserIn(): void
    {
        $client = self::createClient();

        $client->request('GET', '/register');
        $client->submitForm("S'inscrire", [
            'registration_form[email]' => 'carol@example.com',
            'registration_form[username]' => 'carol',
            'registration_form[plainPassword][first]' => 'motdepasse',
            'registration_form[plainPassword][second]' => 'motdepasse',
        ]);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('nav', 'Classements');
        self::assertNotNull(self::em()->getRepository(User::class)->findOneBy(['username' => 'carol']));
    }

    public function testRejectsTakenUsernameAndShortPassword(): void
    {
        $client = self::createClient();
        self::createUser('carol');

        $client->request('GET', '/register');
        $client->submitForm("S'inscrire", [
            'registration_form[email]' => 'autre@example.com',
            'registration_form[username]' => 'carol',
            'registration_form[plainPassword][first]' => 'court',
            'registration_form[plainPassword][second]' => 'court',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Ce pseudo est déjà pris');
        self::assertSelectorTextContains('form', 'Au moins 8 caractères');
    }
}

<?php

namespace App\Tests\Controller;

use App\Tests\Support\CreatesEntities;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    use CreatesEntities;

    public function testLoginWithValidCredentials(): void
    {
        $client = self::createClient();
        self::createUser('alice', password: 'secret123');

        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['_username' => 'alice@example.com', '_password' => 'secret123']);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('nav', 'Profil');
    }

    public function testLoginWithWrongPassword(): void
    {
        $client = self::createClient();
        self::createUser('alice', password: 'secret123');

        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['_username' => 'alice@example.com', '_password' => 'mauvais']);
        $client->followRedirect();

        self::assertSelectorExists('[role="alert"]');
        self::assertSelectorTextContains('nav', 'Connexion');
    }
}

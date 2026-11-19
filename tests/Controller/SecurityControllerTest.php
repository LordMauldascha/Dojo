<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    public function testAdminCanLogIn(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $client->submit($crawler->selectButton('Anmelden')->form(['_username' => 'admin', '_password' => 'test-only']));

        self::assertResponseRedirects('/account');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $client->submit($crawler->selectButton('Anmelden')->form(['_username' => 'admin', '_password' => 'wrong']));

        self::assertResponseRedirects('/login');
    }
}

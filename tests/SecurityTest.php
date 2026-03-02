<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityTest extends WebTestCase
{
    public function testPublicAccessIsDenied()
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedAccessIsAllowed()
    {
        $client = static::createClient();
        $client->request('GET', '/', [], [], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW'   => 'admin123',
        ]);

        $this->assertResponseStatusCodeSame(200);
    }
}

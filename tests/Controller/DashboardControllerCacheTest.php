<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardControllerCacheTest extends WebTestCase
{
    public function testIndexCacheHeaders()
    {
        $client = static::createClient();
        $client->request('GET', '/', [], [], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW'   => 'admin123',
        ]);

        $response = $client->getResponse();

        $this->assertResponseIsSuccessful();

        $this->assertTrue($response->headers->has('Cache-Control'), 'Cache-Control header is missing');

        $cacheControl = $response->headers->get('Cache-Control');

        // Ensure that we at least have s-maxage=300 (or max-age=300).
        $this->assertTrue(
            strpos($cacheControl, 'max-age=300') !== false || strpos($cacheControl, 's-maxage=300') !== false,
            'Cache-Control does not contain max-age=300 or s-maxage=300'
        );
    }
}

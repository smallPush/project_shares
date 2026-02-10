<?php

namespace App\Tests;

use App\Service\StockService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class StockServiceTest extends TestCase
{
    public function testGetPortfolioDataCalculatesProfitability()
    {
        // Mock HttpClient
        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'Global Quote' => [
                '05. price' => '200.00',
                '10. change percent' => '1.5%',
                '06. volume' => '1000000',
            ]
        ]);
        $httpClient->method('request')->willReturn($response);

        // Mock Cache
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(function ($key, $callback) {
            $item = $this->createMock(ItemInterface::class);
            return $callback($item);
        });

        // Mock Logger
        $logger = $this->createMock(LoggerInterface::class);

        // Create temporary portfolio file
        $portfolioData = [
            [
                'symbol' => 'IBM',
                'quantity' => 10,
                'purchase_price' => 150.00
            ]
        ];
        $file = tempnam(sys_get_temp_dir(), 'portfolio');
        file_put_contents($file, json_encode($portfolioData));

        $service = new StockService($httpClient, $file, $logger, $cache, 'demo');
        $result = $service->getPortfolioData();

        // Check if profitability is calculated
        $this->assertCount(1, $result);
        $item = $result[0];

        $this->assertEquals(200.00, $item['price']);
        $this->assertEquals(2000.00, $item['total_value']);

        // Expected profitability: (200 - 150) * 10 = 500
        $this->assertArrayHasKey('profitability', $item);
        $this->assertEquals(500.00, $item['profitability']);

        // Cleanup
        unlink($file);
    }
}

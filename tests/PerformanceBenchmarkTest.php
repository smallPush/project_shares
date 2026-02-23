<?php

namespace App\Tests;

use App\Service\StockService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class PerformanceBenchmarkTest extends TestCase
{
    public function testRequestCount()
    {
        // Mock HttpClient to count requests
        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'Global Quote' => [
                '05. price' => '100.00',
                '10. change percent' => '1.0%',
                '06. volume' => '1000',
            ]
        ]);

        // Expect exactly 1 call for 1 item in the optimized code
        // 1 from fetchStockData (cache miss)
        $httpClient->expects($this->exactly(1))->method('request')->willReturn($response);

        // Mock Cache to simulate miss
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(function ($key, $callback) {
            $item = $this->createMock(ItemInterface::class);
            return $callback($item);
        });

        $logger = $this->createMock(LoggerInterface::class);

        // Create temporary portfolio file with 1 item
        $portfolioData = [
            [
                'symbol' => 'TEST',
                'quantity' => 10,
                'purchase_price' => 100.00
            ]
        ];
        $file = tempnam(sys_get_temp_dir(), 'portfolio_perf');
        file_put_contents($file, json_encode($portfolioData));

        try {
            $service = new StockService($httpClient, $file, $logger, $cache, 'demo');
            $service->getPortfolioSummary();
        } finally {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}

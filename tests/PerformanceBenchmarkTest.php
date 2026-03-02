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

        // Now it fetches 5 items all at once, so `request` is called 5 times directly in `getPortfolioSummary`
        $httpClient->expects($this->exactly(5))->method('request')->willReturn($response);

        // Mock Cache to actually cache the result and only miss on the first hit for each key
        $cache = $this->createMock(CacheInterface::class);

        $cacheData = [];
        $cache->method('get')->willReturnCallback(function ($key, $callback) use (&$cacheData) {
            if (!array_key_exists($key, $cacheData)) {
                $item = $this->createMock(ItemInterface::class);
                $cacheData[$key] = $callback($item);
            }
            return $cacheData[$key];
        });

        $cache->method('delete')->willReturnCallback(function ($key) use (&$cacheData) {
            unset($cacheData[$key]);
            return true;
        });

        $logger = $this->createMock(LoggerInterface::class);

        // Create temporary portfolio file with 5 items
        $portfolioData = [
            [
                'symbol' => 'TEST1',
                'quantity' => 10,
                'purchase_price' => 100.00
            ],
            [
                'symbol' => 'TEST2',
                'quantity' => 10,
                'purchase_price' => 100.00
            ],
            [
                'symbol' => 'TEST3',
                'quantity' => 10,
                'purchase_price' => 100.00
            ],
            [
                'symbol' => 'TEST4',
                'quantity' => 10,
                'purchase_price' => 100.00
            ],
            [
                'symbol' => 'TEST5',
                'quantity' => 10,
                'purchase_price' => 100.00
            ]
        ];
        $file = tempnam(sys_get_temp_dir(), 'portfolio_perf');
        file_put_contents($file, json_encode($portfolioData));

        try {
            $service = new StockService($httpClient, $file, $logger, $cache, 'demo');
            $startTime = microtime(true);
            $service->getPortfolioSummary();
            $endTime = microtime(true);
            echo "\nTime taken for 5 items: " . ($endTime - $startTime) . " seconds\n";
        } finally {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}

<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

class StockService
{
    private ?array $portfolioCache = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%/var/data/portfolio.json')]
        private string $portfolioPath,
        private LoggerInterface $logger,
        private CacheInterface $cache,
        #[Autowire(env: 'ALPHA_VANTAGE_KEY')]
        private string $apiKey
    ) {
    }

    public function getPortfolioData(): array
    {
        return $this->getPortfolioSummary()['stocks'];
    }

    /**
     * Returns both stock data and the grand total of the portfolio.
     * This avoids redundant iterations when both are needed.
     */
    public function getPortfolioSummary(): array
    {
        if ($this->portfolioCache === null) {
            $this->portfolioCache = $this->cache->get('portfolio_data', function (ItemInterface $item) {
                $item->expiresAfter(300); // Cache for 5 minutes

                if (!file_exists($this->portfolioPath)) {
                    return [];
                }

                $json = file_get_contents($this->portfolioPath);
                $portfolio = json_decode($json, true);

                return is_array($portfolio) ? $portfolio : [];
            });
        }

        $portfolio = $this->portfolioCache;

        $results = [];
        $grandTotal = 0.0;

        // Alpha Vantage Free Tier rate limit: 5 requests per minute.
        // We determine if we need to sleep to avoid hitting the limit.
        $shouldSleep = ($this->apiKey === 'demo' || count($portfolio) > 1);

        $uncachedSymbols = [];
        foreach ($portfolio as $item) {
            $symbol = $item['symbol'];
            $cacheKey = 'stock_quote_' . $symbol;

            // Check for cache miss using a sentinel
            $sentinel = '__CACHE_MISS__';
            $cached = $this->cache->get($cacheKey, function () use ($sentinel) {
                return $sentinel;
            });

            if ($cached === $sentinel) {
                $uncachedSymbols[] = $symbol;
                $this->cache->delete($cacheKey);
            }
        }

        $responses = [];
        $uncachedSymbols = array_unique($uncachedSymbols); // Avoid fetching same symbol multiple times
        foreach ($uncachedSymbols as $index => $symbol) {
            if ($shouldSleep && $index > 0) {
                usleep(500000); // Stagger requests by 500ms
            }

            // Initiate request concurrently
            $responses[$symbol] = $this->httpClient->request('GET', 'https://www.alphavantage.co/query', [
                'query' => [
                    'function' => 'GLOBAL_QUOTE',
                    'symbol' => $symbol,
                    'apikey' => $this->apiKey,
                ],
            ]);
        }

        // Process responses and populate cache
        foreach ($responses as $symbol => $response) {
            $cacheKey = 'stock_quote_' . $symbol;
            // The cache->get here handles populating the cache. We need to actually evaluate it.
            $this->cache->get($cacheKey, function (ItemInterface $item) use ($symbol, $response, $shouldSleep) {
                $item->expiresAfter(300); // Cache for 5 minutes

                try {
                    $content = $response->toArray();

                    if (isset($content['Note'])) {
                        throw new \Exception('Alpha Vantage API limit reached: ' . $content['Note']);
                    }

                    if (!isset($content['Global Quote']) || empty($content['Global Quote'])) {
                        throw new \Exception('No data found for symbol: ' . $symbol);
                    }

                    $quote = $content['Global Quote'];

                    return [
                        'price' => (float) ($quote['05. price'] ?? 0),
                        'change_percent' => $quote['10. change percent'] ?? 'N/A',
                        'volume' => $quote['06. volume'] ?? 'N/A',
                        'pe_ratio' => 'N/A',
                        'error' => null
                    ];
                } catch (\Exception $e) {
                    $errorMessage = str_replace($this->apiKey, '***', $e->getMessage());
                    $this->logger->error(sprintf('Alpha Vantage Error (%s): %s', $symbol, $errorMessage));

                    return [
                        'price' => null,
                        'change_percent' => 'N/A',
                        'volume' => 'N/A',
                        'pe_ratio' => 'N/A',
                        'error' => $errorMessage
                    ];
                }
            });
        }

        foreach ($portfolio as $item) {
            $symbol = $item['symbol'];
            $quantity = $item['quantity'];
            $purchasePrice = $item['purchase_price'] ?? null;

            // Fetch from cache since we just populated it
            $data = $this->fetchStockData($symbol, false);

            $totalValue = 0.0;
            if ($data['price'] !== null) {
                $totalValue = $data['price'] * $quantity;
            }

            $profitability = null;
            if ($purchasePrice !== null && $data['price'] !== null) {
                $profitability = $totalValue - ($purchasePrice * $quantity);
            }

            $data['symbol'] = $symbol;
            $data['quantity'] = $quantity;
            $data['purchase_price'] = $purchasePrice;
            $data['total_value'] = $totalValue;
            $data['profitability'] = $profitability;

            $results[] = $data;
            $grandTotal += $totalValue;
        }

        return [
            'stocks' => $results,
            'grand_total' => $grandTotal,
        ];
    }

    private function fetchStockData(string $symbol, bool $shouldSleep = false): array
    {
        return $this->cache->get('stock_quote_' . $symbol, function (ItemInterface $item) use ($symbol, $shouldSleep) {
            $item->expiresAfter(300); // Cache for 5 minutes

            try {
                $response = $this->httpClient->request('GET', 'https://www.alphavantage.co/query', [
                    'query' => [
                        'function' => 'GLOBAL_QUOTE',
                        'symbol' => $symbol,
                        'apikey' => $this->apiKey,
                    ],
                ]);

                $content = $response->toArray();

                if (isset($content['Note'])) {
                    throw new \Exception('Alpha Vantage API limit reached: ' . $content['Note']);
                }

                if (!isset($content['Global Quote']) || empty($content['Global Quote'])) {
                    throw new \Exception('No data found for symbol: ' . $symbol);
                }

                $quote = $content['Global Quote'];

                $result = [
                    'price' => (float) ($quote['05. price'] ?? 0),
                    'change_percent' => $quote['10. change percent'] ?? 'N/A',
                    'volume' => $quote['06. volume'] ?? 'N/A',
                    'pe_ratio' => 'N/A', // GLOBAL_QUOTE doesn't provide PER
                    'error' => null
                ];

                if ($shouldSleep) {
                    usleep(500000);
                }

                return $result;

            } catch (\Exception $e) {
                $errorMessage = str_replace($this->apiKey, '***', $e->getMessage());
                $this->logger->error(sprintf('Alpha Vantage Error (%s): %s', $symbol, $errorMessage));

                if ($shouldSleep) {
                    usleep(500000);
                }

                return [
                    'price' => null,
                    'change_percent' => 'N/A',
                    'volume' => 'N/A',
                    'pe_ratio' => 'N/A',
                    'error' => $errorMessage
                ];
            }
        });
    }
}

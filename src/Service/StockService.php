<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

class StockService
{
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
        if (!file_exists($this->portfolioPath)) {
            return [];
        }

        $json = file_get_contents($this->portfolioPath);
        $portfolio = json_decode($json, true);

        if (!is_array($portfolio)) {
            return [];
        }

        $results = [];

        // Alpha Vantage Free Tier rate limit: 5 requests per minute.
        // We determine if we need to sleep to avoid hitting the limit.
        $shouldSleep = ($this->apiKey === 'demo' || count($portfolio) > 1);

        foreach ($portfolio as $item) {
            $symbol = $item['symbol'];
            $quantity = $item['quantity'];
            $purchasePrice = $item['purchase_price'] ?? null;

            $data = $this->fetchStockData($symbol, $shouldSleep);
            
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
        }

        return $results;
    }

    private function fetchStockData(string $symbol, bool $shouldSleep = false): array
    {
        return $this->cache->get('stock_quote_' . $symbol, function (ItemInterface $item) use ($symbol, $shouldSleep) {
            $item->expiresAfter(300); // Cache for 5 minutes

            $url = sprintf(
                'https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=%s&apikey=%s',
                $symbol,
                $this->apiKey
            );

            try {
                $response = $this->httpClient->request('GET', $url);
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
                $this->logger->error(sprintf('Alpha Vantage Error (%s): %s', $symbol, $e->getMessage()));

                if ($shouldSleep) {
                    usleep(500000);
                }

                return [
                    'price' => null,
                    'change_percent' => 'N/A',
                    'volume' => 'N/A',
                    'pe_ratio' => 'N/A',
                    'error' => $e->getMessage()
                ];
            }
        });
    }
}

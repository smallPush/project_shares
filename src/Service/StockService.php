<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class StockService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%/var/data/portfolio.json')]
        private string $portfolioPath,
        private LoggerInterface $logger,
        #[Autowire(env: 'ALPHA_VANTAGE_KEY')]
        private string $apiKey = 'demo'
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

        foreach ($portfolio as $item) {
            $symbol = $item['symbol'];
            $quantity = $item['quantity'];

            $data = $this->fetchStockData($symbol);
            
            $totalValue = 0.0;
            if ($data['price'] !== null) {
                $totalValue = $data['price'] * $quantity;
            }

            $data['symbol'] = $symbol;
            $data['quantity'] = $quantity;
            $data['total_value'] = $totalValue;

            $results[] = $data;

            // Alpha Vantage Free Tier rate limit: 5 requests per minute.
            // We sleep for a bit to avoid hitting the limit immediately if many stocks exist.
            if ($this->apiKey === 'demo' || count($portfolio) > 1) {
                // USleep for 500ms between calls as a safety measure
                usleep(500000); 
            }
        }

        return $results;
    }

    private function fetchStockData(string $symbol): array
    {
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

            return [
                'price' => (float) ($quote['05. price'] ?? 0),
                'change_percent' => $quote['10. change percent'] ?? 'N/A',
                'volume' => $quote['06. volume'] ?? 'N/A',
                'pe_ratio' => 'N/A', // GLOBAL_QUOTE doesn't provide PER
                'error' => null
            ];

        } catch (\Exception $e) {
            $this->logger->error(sprintf('Alpha Vantage Error (%s): %s', $symbol, $e->getMessage()));
            return [
                'price' => null,
                'change_percent' => 'N/A',
                'volume' => 'N/A',
                'pe_ratio' => 'N/A',
                'error' => $e->getMessage()
            ];
        }
    }
}
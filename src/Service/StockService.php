<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
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
        return $this->getPortfolioSummary()['stocks'];
    }

    /**
     * Returns both stock data and the grand total of the portfolio.
     * This avoids redundant iterations when both are needed.
     */
    public function getPortfolioSummary(): array
    {
        if (!file_exists($this->portfolioPath)) {
            return [
                'stocks' => [],
                'grand_total' => 0.0,
            ];
        }

        $json = file_get_contents($this->portfolioPath);
        $portfolio = json_decode($json, true);

        if (!is_array($portfolio)) {
            return [
                'stocks' => [],
                'grand_total' => 0.0,
            ];
        }

        $results = [];
        $grandTotal = 0.0;
        $stockDataBySymbol = [];
        $symbolsToFetch = [];

        // 1. Check cache for all symbols
        foreach ($portfolio as $item) {
            $symbol = $item['symbol'];
            // We use get() to check the cache. If it's a miss, it will return null.
            // Note: Symfony Cache will cache this null value. We will delete it before
            // storing the real data later to avoid the "broken cache" issue.
            $data = $this->cache->get('stock_quote_' . $symbol, function (ItemInterface $item) {
                return null;
            });

            if ($data === null) {
                $symbolsToFetch[] = $symbol;
            } else {
                $stockDataBySymbol[$symbol] = $data;
            }
        }

        // 2. Fetch missing symbols in parallel batches to respect rate limits
        if (!empty($symbolsToFetch)) {
            // Unique symbols only to avoid redundant requests for the same stock in a portfolio
            $uniqueSymbols = array_unique($symbolsToFetch);

            // Alpha Vantage Free Tier: 5 requests per minute.
            // We batch them to allow some parallelism while providing a delay between batches.
            $batchSize = 5;
            $chunks = array_chunk($uniqueSymbols, $batchSize);

            foreach ($chunks as $index => $batch) {
                // If we have multiple batches, we wait between them.
                // 2 seconds is a compromise between speed and rate-limit safety.
                if ($index > 0) {
                    usleep(2000000);
                }

                $batchResponses = [];
                $symbolMap = new \SplObjectStorage();

                foreach ($batch as $symbol) {
                    $response = $this->requestStockData($symbol);
                    $batchResponses[] = $response;
                    $symbolMap[$response] = $symbol;
                }

                foreach ($this->httpClient->stream($batchResponses) as $response => $chunk) {
                    if ($chunk->isLast()) {
                        $symbol = $symbolMap[$response];
                        $data = $this->processStockResponse($symbol, $response);

                        // If we successfully got data, we store it in cache.
                        if ($data['error'] === null) {
                            // Important: delete the 'null' cached during the check phase
                            $this->cache->delete('stock_quote_' . $symbol);

                            $this->cache->get('stock_quote_' . $symbol, function (ItemInterface $item) use ($data) {
                                $item->expiresAfter(300); // 5 minutes
                                return $data;
                            });
                        }

                        $stockDataBySymbol[$symbol] = $data;
                    }
                }
            }
        }

        // 3. Assemble final results and calculate totals
        foreach ($portfolio as $item) {
            $symbol = $item['symbol'];
            $quantity = $item['quantity'];
            $purchasePrice = $item['purchase_price'] ?? null;

            $data = $stockDataBySymbol[$symbol] ?? [
                'price' => null,
                'change_percent' => 'N/A',
                'volume' => 'N/A',
                'pe_ratio' => 'N/A',
                'error' => 'Data not found'
            ];
            
            $totalValue = 0.0;
            if ($data['price'] !== null) {
                $totalValue = $data['price'] * $quantity;
            }

            $profitability = null;
            if ($purchasePrice !== null && $data['price'] !== null) {
                $profitability = $totalValue - ($purchasePrice * $quantity);
            }

            // Create result array including portfolio-specific data
            $result = $data;
            $result['symbol'] = $symbol;
            $result['quantity'] = $quantity;
            $result['purchase_price'] = $purchasePrice;
            $result['total_value'] = $totalValue;
            $result['profitability'] = $profitability;

            $results[] = $result;
            $grandTotal += $totalValue;
        }

        return [
            'stocks' => $results,
            'grand_total' => $grandTotal,
        ];
    }

    private function requestStockData(string $symbol): ResponseInterface
    {
        $url = sprintf(
            'https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=%s&apikey=%s',
            $symbol,
            $this->apiKey
        );
        
        return $this->httpClient->request('GET', $url);
    }

    private function processStockResponse(string $symbol, ResponseInterface $response): array
    {
        try {
            // toArray() will throw if the response is not 2xx or if JSON is invalid
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

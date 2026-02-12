<?php

namespace App\Console\Commands;

use App\Models\MetalPrice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchMetalPrices extends Command
{
    protected $signature = 'metals:fetch-prices';

    protected $description = 'Fetch current precious metal spot prices from the metals API';

    /**
     * Metal symbols from the API mapped to our metal types.
     * Using USDX** format which gives direct USD price per troy ounce.
     */
    protected array $metalSymbols = [
        'USDXAU' => 'gold',
        'USDXAG' => 'silver',
        'USDXPT' => 'platinum',
        'USDXPD' => 'palladium',
        'USDXRH' => 'rhodium',
    ];

    /**
     * Grams per troy ounce for weight conversions.
     */
    protected float $gramsPerTroyOunce = 31.1035;

    /**
     * Grams per pennyweight (dwt) for weight conversions.
     */
    protected float $gramsPerPennyweight = 1.55517;

    public function handle(): int
    {
        $apiKey = config('services.metals.api_key');
        $apiUrl = config('services.metals.api_url');

        if (! $apiKey) {
            $this->error('METALS_API_KEY is not configured.');

            return self::FAILURE;
        }

        $this->info('Fetching metal prices from API...');

        try {
            // Request the base metal symbols - API returns both XAU and USDXAU formats
            $response = Http::get($apiUrl, [
                'access_key' => $apiKey,
                'symbols' => 'XAU,XAG,XPT,XPD,XRH',
            ]);

            if (! $response->successful()) {
                $this->error('API request failed: '.$response->status());
                Log::error('Metal prices API failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return self::FAILURE;
            }

            $data = $response->json();

            if (! isset($data['rates']) || ! $data['success']) {
                $this->error('Invalid API response: '.($data['error']['info'] ?? 'Unknown error'));
                Log::error('Metal prices API invalid response', ['data' => $data]);

                return self::FAILURE;
            }

            $updatedCount = 0;

            foreach ($data['rates'] as $symbol => $rate) {
                if (! isset($this->metalSymbols[$symbol])) {
                    continue;
                }

                $metalType = $this->metalSymbols[$symbol];

                // USDX** rates are direct USD price per troy ounce
                $pricePerOunce = round((float) $rate, 2);
                $pricePerGram = round($pricePerOunce / $this->gramsPerTroyOunce, 4);
                $pricePerDwt = round($pricePerGram * $this->gramsPerPennyweight, 4);

                // Update or create the price record for this metal
                MetalPrice::updateOrCreate(
                    ['metal_type' => $metalType, 'purity' => 'spot'],
                    [
                        'price_per_gram' => $pricePerGram,
                        'price_per_ounce' => $pricePerOunce,
                        'price_per_dwt' => $pricePerDwt,
                        'currency' => 'USD',
                        'source' => 'metals-api',
                        'effective_at' => now(),
                    ]
                );

                $this->line("  {$metalType}: \${$pricePerOunce}/oz");
                $updatedCount++;
            }

            $this->info("Updated {$updatedCount} metal prices successfully.");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('Failed to fetch metal prices: '.$e->getMessage());
            Log::error('Metal prices fetch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}

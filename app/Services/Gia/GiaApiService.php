<?php

namespace App\Services\Gia;

use App\Models\StoreIntegration;
use App\Services\StoreContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GiaApiService
{
    protected ?StoreIntegration $integration = null;

    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Get GIA integration for current store.
     */
    protected function getIntegration(): ?StoreIntegration
    {
        if ($this->integration) {
            return $this->integration;
        }

        $storeId = $this->storeContext->getCurrentStoreId();
        if (! $storeId) {
            return null;
        }

        $this->integration = StoreIntegration::findActiveForStore($storeId, StoreIntegration::PROVIDER_GIA);

        return $this->integration;
    }

    /**
     * Check if GIA integration is configured for the current store.
     */
    public function isConfigured(): bool
    {
        return $this->getIntegration() !== null;
    }

    /**
     * Fetch GIA report data by report number.
     *
     * @return array{data: array|null, errors: array|null}
     */
    public function getReport(string $reportNumber): array
    {
        $query = $this->buildReportQuery($reportNumber);

        try {
            $response = $this->makeGraphQlRequest($query);

            if ($errors = data_get($response, 'errors')) {
                Log::warning('GIA API error', [
                    'report_number' => $reportNumber,
                    'errors' => $errors,
                ]);

                return [
                    'data' => null,
                    'errors' => $errors,
                ];
            }

            // Record usage
            $this->getIntegration()?->recordUsage();

            return [
                'data' => data_get($response, 'data.getReport'),
                'errors' => null,
            ];
        } catch (\Exception $e) {
            Log::error('GIA API request failed', [
                'report_number' => $reportNumber,
                'error' => $e->getMessage(),
            ]);

            // Mark integration as having an error if it's a configuration issue
            if (str_contains($e->getMessage(), 'not configured') || str_contains($e->getMessage(), '401')) {
                $this->getIntegration()?->markAsError($e->getMessage());
            }

            return [
                'data' => null,
                'errors' => [['message' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Build the GraphQL query for fetching a report.
     */
    protected function buildReportQuery(string $reportNumber): string
    {
        $query = <<<'GRAPHQL'
        {
            getReport(report_number: "%s") {
                report_date
                report_number
                report_type
                links {
                    image
                    digital_card
                    pdf
                }
                results {
                    ... on DiamondGradingReportResults {
                        shape_and_cutting_style
                        carat_weight
                        color_grade
                        clarity_grade
                        cut_grade
                        diamond_type
                        fluorescence
                        clarity_characteristics
                        color_distribution
                        country_of_origin
                        inscriptions
                        measurements
                        polish
                        symmetry
                        data {
                            shape {
                                shape_code
                                shape_category
                                shape_group
                            }
                            color_grades {
                                ...on DZColorGrade {
                                    color_grade_code
                                    color_modifier
                                }
                            }
                            cut
                            weight {
                                weight
                                weight_unit
                            }
                            clarity
                        }
                    }
                }
            }
        }
        GRAPHQL;

        return json_encode([
            'query' => sprintf($query, $reportNumber),
            'variables' => new \stdClass,
        ]);
    }

    /**
     * Make a GraphQL request to the GIA API.
     */
    protected function makeGraphQlRequest(string $query): array
    {
        $integration = $this->getIntegration();

        if (! $integration) {
            throw new \RuntimeException('GIA integration is not configured. Please configure it in Settings > Integrations.');
        }

        $url = $integration->getGiaApiUrl();
        $key = $integration->getGiaApiKey();

        if (! $key) {
            throw new \RuntimeException('GIA API key is not configured');
        }

        $response = Http::withHeaders([
            'Authorization' => $key,
            'Content-Type' => 'application/json',
        ])->withBody($query, 'application/json')->post($url);

        if (! $response->successful()) {
            throw new \RuntimeException('GIA API request failed: '.$response->status());
        }

        return $response->json();
    }

    /**
     * Get the mapping from GIA fields to Loose Stones template field names.
     *
     * @return array<string, string>
     */
    public static function getLooseStonesMapping(): array
    {
        return [
            // Shape and weight
            'main_stone_shape' => 'shape_and_cutting_style',
            'main_stone_wt' => 'carat_weight',
            'stone_shape' => 'shape_and_cutting_style',
            'stone_weight' => 'carat_weight',

            // Color and clarity
            'diamond_color' => 'data.color_grades.color_grade_code',
            'color' => 'data.color_grades.color_grade_code',
            'diamond_clarity' => 'data.clarity',
            'clarity' => 'data.clarity',

            // Cut quality
            'diamond_cut' => 'cut_grade',
            'cut' => 'cut_grade',
            'polish' => 'polish',
            'symmetry' => 'symmetry',
            'fluorescence' => 'fluorescence',
        ];
    }

    /**
     * Get the mapping from GIA fields to Earrings template field names for main stone.
     *
     * @return array<string, string>
     */
    public static function getEarringsMainStoneMapping(): array
    {
        return [
            // Shape and weight
            'main_stone_shape' => 'shape_and_cutting_style',
            'main_stone_wt' => 'carat_weight',

            // Color and clarity (shared fields)
            'diamond_color' => 'data.color_grades.color_grade_code',
            'diamond_clarity' => 'data.clarity',
            'diamond_cut' => 'cut_grade',

            // Main stone specific quality fields
            'main_stone_polish' => 'polish',
            'main_stone_symmetry' => 'symmetry',
        ];
    }

    /**
     * Get the mapping for second stone (diamond studs).
     *
     * @return array<string, string>
     */
    public static function getEarringsSecondStoneMapping(): array
    {
        return [
            'second_stone_shape' => 'shape_and_cutting_style',
            'second_stone_weight' => 'carat_weight',
            'second_stone_color' => 'data.color_grades.color_grade_code',
            'second_stone_clarity' => 'data.clarity',
            'second_stone_cut' => 'cut_grade',
            'second_stone_polish' => 'polish',
            'second_stone_symmetry' => 'symmetry',
        ];
    }

    /**
     * @deprecated Use getLooseStonesMapping() or getEarringsMainStoneMapping() instead
     */
    public static function getMainStoneMapping(): array
    {
        return self::getLooseStonesMapping();
    }

    /**
     * @deprecated Use getEarringsSecondStoneMapping() instead
     */
    public static function getSecondStoneMapping(): array
    {
        return self::getEarringsSecondStoneMapping();
    }

    /**
     * Extract mapped values from GIA report results.
     *
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    public static function extractMappedValues(array $results, array $mapping): array
    {
        $values = [];

        foreach ($mapping as $fieldName => $giaPath) {
            $values[$fieldName] = data_get($results, $giaPath);
        }

        return $values;
    }

    /**
     * Parse measurements string into components.
     *
     * @return array{min_diameter: ?string, max_diameter: ?string, depth: ?string}
     */
    public static function parseMeasurements(?string $measurements): array
    {
        if (! $measurements) {
            return [
                'min_diameter' => null,
                'max_diameter' => null,
                'depth' => null,
            ];
        }

        $pattern = '/(\d+\.?\d*)/';
        preg_match_all($pattern, $measurements, $matches);

        if (count($matches[0]) >= 3) {
            return [
                'min_diameter' => $matches[0][0],
                'max_diameter' => $matches[0][1],
                'depth' => $matches[0][2],
            ];
        }

        return [
            'min_diameter' => null,
            'max_diameter' => null,
            'depth' => null,
        ];
    }

    /**
     * Get diamond weight range label.
     */
    public static function getWeightRangeLabel(float $weight): ?string
    {
        // Values must match select option values (e.g., '51-75', '76-99', '100-125')
        $ranges = self::getDiamondWeightRanges();

        foreach ($ranges as $range) {
            if ($weight >= $range['low'] && $weight <= $range['high']) {
                return $range['value'];
            }
        }

        return null;
    }

    /**
     * Get diamond weight ranges for categorization.
     * Values match the select field option values.
     *
     * @return array<array{low: float, high: float, value: string}>
     */
    public static function getDiamondWeightRanges(): array
    {
        return [
            ['low' => 0.00, 'high' => 0.25, 'value' => '0-25'],
            ['low' => 0.26, 'high' => 0.50, 'value' => '26-50'],
            ['low' => 0.51, 'high' => 0.75, 'value' => '51-75'],
            ['low' => 0.76, 'high' => 0.99, 'value' => '76-99'],
            ['low' => 1.00, 'high' => 1.25, 'value' => '100-125'],
            ['low' => 1.26, 'high' => 1.50, 'value' => '126-150'],
            ['low' => 1.51, 'high' => 1.99, 'value' => '151-199'],
            ['low' => 2.00, 'high' => 2.50, 'value' => '200-250'],
            ['low' => 2.51, 'high' => 3.00, 'value' => '251-300'],
            ['low' => 3.01, 'high' => 5.00, 'value' => '301-500'],
            ['low' => 5.01, 'high' => 99999.00, 'value' => '500-plus'],
        ];
    }
}

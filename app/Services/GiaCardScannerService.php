<?php

namespace App\Services;

use Aws\Textract\TextractClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GiaCardScannerService
{
    protected TextractClient $textract;

    public function __construct()
    {
        $this->textract = new TextractClient([
            'version' => 'latest',
            'region' => config('services.textract.region'),
            'credentials' => [
                'key' => config('services.textract.key'),
                'secret' => config('services.textract.secret'),
            ],
        ]);
    }

    /**
     * Scan a GIA card image and extract certificate data.
     */
    public function scanImage(UploadedFile $file): array
    {
        $result = $this->textract->analyzeDocument([
            'Document' => [
                'Bytes' => file_get_contents($file->getRealPath()),
            ],
            'FeatureTypes' => ['FORMS', 'TABLES'],
        ]);

        $response = $result->toArray();

        return $this->parseTextractResponse($response);
    }

    /**
     * Store the scanned image for reference.
     */
    public function storeScannedImage(UploadedFile $file, int $storeId): string
    {
        $disk = config('filesystems.default') === 's3' ? 's3' : 'public';

        return $file->store("gia-scans/{$storeId}", $disk);
    }

    /**
     * Parse Textract response and extract GIA certificate fields.
     */
    protected function parseTextractResponse(array $response): array
    {
        // Build key-value map from Textract blocks
        $keyValuePairs = $this->extractKeyValuePairs($response);
        $allText = $this->extractAllText($response);

        // Log for debugging
        Log::debug('GIA Scanner - Key-Value Pairs:', $keyValuePairs);
        Log::debug('GIA Scanner - All Text:', ['text' => $allText]);

        return [
            'certificate_number' => $this->findValue($keyValuePairs, $allText, [
                'Report Number',
                'GIA Report Number',
                'Report No',
                'Certificate Number',
                'Cert No',
            ]),
            'issue_date' => $this->findValue($keyValuePairs, $allText, [
                'Date',
                'Report Date',
                'Issue Date',
            ]),
            'shape' => $this->findValue($keyValuePairs, $allText, [
                'Shape',
                'Shape and Cutting Style',
                'Cut',
            ]),
            'carat_weight' => $this->extractCaratWeight($keyValuePairs, $allText),
            'color_grade' => $this->findValue($keyValuePairs, $allText, [
                'Color Grade',
                'Color',
            ]),
            'clarity_grade' => $this->findValue($keyValuePairs, $allText, [
                'Clarity Grade',
                'Clarity',
            ]),
            'cut_grade' => $this->findValue($keyValuePairs, $allText, [
                'Cut Grade',
            ]),
            'polish' => $this->findValue($keyValuePairs, $allText, [
                'Polish',
            ]),
            'symmetry' => $this->findValue($keyValuePairs, $allText, [
                'Symmetry',
            ]),
            'fluorescence' => $this->findValue($keyValuePairs, $allText, [
                'Fluorescence',
            ]),
            'measurements' => $this->extractMeasurements($keyValuePairs, $allText),
            'inscription' => $this->findValue($keyValuePairs, $allText, [
                'Inscription',
                'Inscriptions',
            ]),
            'comments' => $this->findValue($keyValuePairs, $allText, [
                'Comments',
                'Additional Inscriptions',
            ]),
            'raw_data' => $response,
        ];
    }

    /**
     * Extract key-value pairs from Textract blocks.
     */
    protected function extractKeyValuePairs(array $response): array
    {
        $blocks = $response['Blocks'] ?? [];
        $blockMap = [];
        $keyMap = [];
        $valueMap = [];

        // Build maps of blocks by ID and type
        foreach ($blocks as $block) {
            $blockMap[$block['Id']] = $block;

            if ($block['BlockType'] === 'KEY_VALUE_SET') {
                if (in_array('KEY', $block['EntityTypes'] ?? [])) {
                    $keyMap[$block['Id']] = $block;
                } elseif (in_array('VALUE', $block['EntityTypes'] ?? [])) {
                    $valueMap[$block['Id']] = $block;
                }
            }
        }

        // Extract key-value pairs
        $pairs = [];
        foreach ($keyMap as $keyBlock) {
            $keyText = $this->getTextFromBlock($keyBlock, $blockMap);
            $valueText = '';

            // Find associated value block
            foreach ($keyBlock['Relationships'] ?? [] as $relationship) {
                if ($relationship['Type'] === 'VALUE') {
                    foreach ($relationship['Ids'] as $valueId) {
                        if (isset($valueMap[$valueId])) {
                            $valueText = $this->getTextFromBlock($valueMap[$valueId], $blockMap);
                        }
                    }
                }
            }

            if ($keyText) {
                $pairs[trim($keyText)] = trim($valueText);
            }
        }

        return $pairs;
    }

    /**
     * Get text content from a block.
     */
    protected function getTextFromBlock(array $block, array $blockMap): string
    {
        $text = '';

        foreach ($block['Relationships'] ?? [] as $relationship) {
            if ($relationship['Type'] === 'CHILD') {
                foreach ($relationship['Ids'] as $childId) {
                    if (isset($blockMap[$childId]) && $blockMap[$childId]['BlockType'] === 'WORD') {
                        $text .= ($blockMap[$childId]['Text'] ?? '').' ';
                    }
                }
            }
        }

        return trim($text);
    }

    /**
     * Extract all text from the document.
     */
    protected function extractAllText(array $response): string
    {
        $blocks = $response['Blocks'] ?? [];
        $lines = [];

        foreach ($blocks as $block) {
            if ($block['BlockType'] === 'LINE') {
                $lines[] = $block['Text'] ?? '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Find a value by searching multiple possible labels.
     */
    protected function findValue(array $keyValuePairs, string $allText, array $possibleLabels): ?string
    {
        // First try exact key-value matches
        foreach ($possibleLabels as $label) {
            foreach ($keyValuePairs as $key => $value) {
                if (stripos($key, $label) !== false && $value) {
                    return $this->cleanValue($value);
                }
            }
        }

        // Fall back to pattern matching in text
        foreach ($possibleLabels as $label) {
            $pattern = '/'.preg_quote($label, '/').'\s*[:.]?\s*([^\n]+)/i';
            if (preg_match($pattern, $allText, $matches)) {
                return $this->cleanValue($matches[1]);
            }
        }

        return null;
    }

    /**
     * Extract carat weight, handling various formats.
     */
    protected function extractCaratWeight(array $keyValuePairs, string $allText): ?string
    {
        $value = $this->findValue($keyValuePairs, $allText, [
            'Carat Weight',
            'Weight',
            'Carat',
        ]);

        if ($value) {
            // Extract numeric value (e.g., "1.05 carat" -> "1.05")
            if (preg_match('/(\d+\.?\d*)/', $value, $matches)) {
                return $matches[1];
            }
        }

        return $value;
    }

    /**
     * Extract measurements from the document.
     */
    protected function extractMeasurements(array $keyValuePairs, string $allText): ?array
    {
        $value = $this->findValue($keyValuePairs, $allText, [
            'Measurements',
            'Dimensions',
        ]);

        if (! $value) {
            // Try to find measurement pattern in text (e.g., "6.45 - 6.48 x 3.97 mm")
            if (preg_match('/(\d+\.?\d*)\s*[-x]\s*(\d+\.?\d*)\s*[x]\s*(\d+\.?\d*)/i', $allText, $matches)) {
                return [
                    'length' => (float) $matches[1],
                    'width' => (float) $matches[2],
                    'depth' => (float) $matches[3],
                ];
            }

            return null;
        }

        // Parse measurement string (e.g., "6.45 - 6.48 x 3.97 mm")
        if (preg_match('/(\d+\.?\d*)\s*[-x]\s*(\d+\.?\d*)\s*[x]\s*(\d+\.?\d*)/i', $value, $matches)) {
            return [
                'length' => (float) $matches[1],
                'width' => (float) $matches[2],
                'depth' => (float) $matches[3],
            ];
        }

        return null;
    }

    /**
     * Clean extracted value.
     */
    protected function cleanValue(string $value): string
    {
        // Remove common suffixes and clean whitespace
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim($value);

        return $value;
    }

    /**
     * Get mapping from GIA field names to template canonical names.
     *
     * This maps GIA certificate fields to the standardized canonical_name
     * used in ProductTemplateField, allowing auto-population of template
     * fields regardless of their display label.
     *
     * @return array<string, string> Map of GIA field => canonical_name
     */
    public static function getGiaToCanonicalMapping(): array
    {
        return [
            // Certificate info
            'certificate_number' => 'certificate_number',

            // Weight and measurements
            'carat_weight' => 'total_carat_weight',
            'measurements' => 'measurements',

            // Grades
            'color_grade' => 'color_grade',
            'clarity_grade' => 'clarity_grade',
            'cut_grade' => 'cut_grade',
            'polish' => 'polish',
            'symmetry' => 'symmetry',
            'fluorescence' => 'fluorescence',

            // Shape
            'shape' => 'gemstone_shape',

            // Additional info
            'inscription' => 'inscription',
            'comments' => 'certificate_comments',
        ];
    }

    /**
     * Map extracted GIA data to template field values based on canonical names.
     *
     * @param  array  $extractedData  The extracted GIA data
     * @param  array  $templateFields  Array of template fields with 'id' and 'canonical_name'
     * @return array<int, string> Map of field_id => value
     */
    public static function mapToTemplateFields(array $extractedData, array $templateFields): array
    {
        $mapping = self::getGiaToCanonicalMapping();
        $result = [];

        // Build a lookup of canonical_name => field_id
        $canonicalToFieldId = [];
        foreach ($templateFields as $field) {
            if (! empty($field['canonical_name'])) {
                $canonicalToFieldId[$field['canonical_name']] = $field['id'];
            }
        }

        // Map GIA data to template fields
        foreach ($mapping as $giaField => $canonicalName) {
            // Check if template has a field with this canonical name
            if (! isset($canonicalToFieldId[$canonicalName])) {
                continue;
            }

            $fieldId = $canonicalToFieldId[$canonicalName];
            $value = $extractedData[$giaField] ?? null;

            // Handle special cases
            if ($value === null) {
                continue;
            }

            // Convert arrays to strings for storage
            if (is_array($value)) {
                if ($giaField === 'measurements') {
                    // Format measurements as "L x W x D mm"
                    $value = sprintf(
                        '%s x %s x %s mm',
                        $value['length'] ?? '0',
                        $value['width'] ?? '0',
                        $value['depth'] ?? '0'
                    );
                } else {
                    $value = json_encode($value);
                }
            }

            $result[$fieldId] = (string) $value;
        }

        // Also check if gemstone_type field exists and set to "diamond" for GIA certs
        if (isset($canonicalToFieldId['gemstone_type'])) {
            $result[$canonicalToFieldId['gemstone_type']] = 'diamond';
        }

        return $result;
    }
}

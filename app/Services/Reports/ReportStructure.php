<?php

namespace App\Services\Reports;

use JsonSerializable;

/**
 * Represents a report structure with rich cell types.
 *
 * Cell Types:
 * - text: Plain text
 * - currency: Formatted money (e.g., $1,234.56)
 * - number: Formatted number
 * - percentage: Percentage with % sign
 * - date: Formatted date
 * - link: Clickable link with href
 * - badge: Status badge with variant/color
 * - image: Image with src
 * - html: Raw HTML
 *
 * Each column definition specifies the type and type-specific options.
 * Data can then be simple values or objects with additional properties.
 */
class ReportStructure implements JsonSerializable
{
    public const TYPE_TEXT = 'text';

    public const TYPE_CURRENCY = 'currency';

    public const TYPE_NUMBER = 'number';

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_DATE = 'date';

    public const TYPE_LINK = 'link';

    public const TYPE_BADGE = 'badge';

    public const TYPE_IMAGE = 'image';

    public const TYPE_HTML = 'html';

    protected ?string $logo = null;

    protected ?string $title = null;

    protected ?string $subtitle = null;

    protected array $tables = [];

    protected array $summaryBlocks = [];

    protected ?string $footer = null;

    protected array $metadata = [];

    /**
     * Create from array (e.g., from database JSON).
     */
    public static function fromArray(array $data): self
    {
        $structure = new self;
        $structure->logo = $data['logo'] ?? null;
        $structure->title = $data['title'] ?? null;
        $structure->subtitle = $data['subtitle'] ?? null;
        $structure->tables = $data['tables'] ?? [];
        $structure->summaryBlocks = $data['summary_blocks'] ?? [];
        $structure->footer = $data['footer'] ?? null;
        $structure->metadata = $data['metadata'] ?? [];

        return $structure;
    }

    /**
     * Create from JSON string.
     */
    public static function fromJson(string $json): self
    {
        return self::fromArray(json_decode($json, true) ?? []);
    }

    // Fluent setters

    public function setLogo(?string $logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function setSubtitle(?string $subtitle): self
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function setFooter(?string $footer): self
    {
        $this->footer = $footer;

        return $this;
    }

    /**
     * Add a table to the report.
     *
     * @param  string  $name  Table identifier
     * @param  string  $heading  Display heading
     * @param  array  $columns  Column definitions with rich types
     * @param  string  $dataKey  Key in data array for rows
     * @param  array  $options  Additional table options (footer, totals, etc.)
     */
    public function addTable(string $name, string $heading, array $columns, string $dataKey, array $options = []): self
    {
        $this->tables[$name] = [
            'name' => $name,
            'heading' => $heading,
            'columns' => $columns,
            'data_key' => $dataKey,
            'show_if_empty' => $options['show_if_empty'] ?? false,
            'empty_message' => $options['empty_message'] ?? 'No data available.',
            'footer' => $options['footer'] ?? null,
            'totals' => $options['totals'] ?? null,
        ];

        return $this;
    }

    /**
     * Remove a table by name.
     */
    public function removeTable(string $name): self
    {
        unset($this->tables[$name]);

        return $this;
    }

    /**
     * Add a column to an existing table.
     */
    public function addColumn(string $tableName, array $column): self
    {
        if (isset($this->tables[$tableName])) {
            $this->tables[$tableName]['columns'][] = $column;
        }

        return $this;
    }

    /**
     * Remove a column from a table.
     */
    public function removeColumn(string $tableName, string $columnKey): self
    {
        if (isset($this->tables[$tableName])) {
            $this->tables[$tableName]['columns'] = array_values(array_filter(
                $this->tables[$tableName]['columns'],
                fn ($col) => ($col['key'] ?? '') !== $columnKey
            ));
        }

        return $this;
    }

    /**
     * Add a summary block (key-value display).
     */
    public function addSummaryBlock(string $name, string $heading, array $items): self
    {
        $this->summaryBlocks[$name] = [
            'name' => $name,
            'heading' => $heading,
            'items' => $items,
        ];

        return $this;
    }

    /**
     * Set metadata.
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    // Column builder helpers

    /**
     * Create a text column.
     */
    public static function textColumn(string $key, string $label, array $options = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => self::TYPE_TEXT,
        ], $options);
    }

    /**
     * Create a currency column.
     */
    public static function currencyColumn(string $key, string $label, string $currency = 'USD', array $options = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => self::TYPE_CURRENCY,
            'currency' => $currency,
            'decimals' => 2,
        ], $options);
    }

    /**
     * Create a number column.
     */
    public static function numberColumn(string $key, string $label, array $options = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => self::TYPE_NUMBER,
            'decimals' => $options['decimals'] ?? 0,
        ], $options);
    }

    /**
     * Create a percentage column.
     */
    public static function percentageColumn(string $key, string $label, array $options = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => self::TYPE_PERCENTAGE,
            'decimals' => $options['decimals'] ?? 1,
        ], $options);
    }

    /**
     * Create a date column.
     */
    public static function dateColumn(string $key, string $label, string $format = 'm-d-Y', array $options = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => self::TYPE_DATE,
            'format' => $format,
        ], $options);
    }

    /**
     * Create a link column.
     *
     * @param  string  $hrefTemplate  URL template with {field} placeholders (e.g., "/orders/{id}")
     */
    public static function linkColumn(string $key, string $label, string $hrefTemplate, array $options = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => self::TYPE_LINK,
            'href_template' => $hrefTemplate,
            'target' => $options['target'] ?? '_self',
        ], $options);
    }

    /**
     * Create a badge/status column.
     *
     * @param  array  $variants  Map of values to badge variants (e.g., ['completed' => 'success', 'pending' => 'warning'])
     */
    public static function badgeColumn(string $key, string $label, array $variants = [], array $options = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => self::TYPE_BADGE,
            'variants' => $variants,
            'default_variant' => $options['default_variant'] ?? 'secondary',
        ], $options);
    }

    /**
     * Create an image column.
     */
    public static function imageColumn(string $key, string $label, array $options = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => self::TYPE_IMAGE,
            'width' => $options['width'] ?? 50,
            'height' => $options['height'] ?? 50,
            'fallback' => $options['fallback'] ?? null,
        ], $options);
    }

    /**
     * Create an HTML column (for custom rendering).
     */
    public static function htmlColumn(string $key, string $label, array $options = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => self::TYPE_HTML,
        ], $options);
    }

    // Getters

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getSubtitle(): ?string
    {
        return $this->subtitle;
    }

    public function getTables(): array
    {
        return $this->tables;
    }

    public function getTable(string $name): ?array
    {
        return $this->tables[$name] ?? null;
    }

    public function getSummaryBlocks(): array
    {
        return $this->summaryBlocks;
    }

    public function getFooter(): ?string
    {
        return $this->footer;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Convert to array for JSON storage.
     */
    public function toArray(): array
    {
        return [
            'logo' => $this->logo,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'tables' => array_values($this->tables),
            'summary_blocks' => array_values($this->summaryBlocks),
            'footer' => $this->footer,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Get a description of this structure for AI.
     */
    public function describeForAI(): string
    {
        $desc = "REPORT STRUCTURE:\n";

        if ($this->title) {
            $desc .= "- Title: {$this->title}\n";
        }

        if ($this->subtitle) {
            $desc .= "- Subtitle: {$this->subtitle}\n";
        }

        if ($this->logo) {
            $desc .= "- Has logo\n";
        }

        foreach ($this->tables as $table) {
            $desc .= "\nTable: {$table['heading']}\n";
            $desc .= "  Data key: {$table['data_key']}\n";
            $desc .= "  Columns:\n";
            foreach ($table['columns'] as $col) {
                $type = $col['type'] ?? 'text';
                $desc .= "    - {$col['label']} (key: {$col['key']}, type: {$type}";

                // Add type-specific info
                if ($type === 'link' && isset($col['href_template'])) {
                    $desc .= ", href: {$col['href_template']}";
                } elseif ($type === 'badge' && isset($col['variants'])) {
                    $desc .= ', variants: '.json_encode($col['variants']);
                } elseif ($type === 'currency' && isset($col['currency'])) {
                    $desc .= ", currency: {$col['currency']}";
                } elseif ($type === 'date' && isset($col['format'])) {
                    $desc .= ", format: {$col['format']}";
                }

                $desc .= ")\n";
            }
        }

        foreach ($this->summaryBlocks as $block) {
            $desc .= "\nSummary Block: {$block['heading']}\n";
            foreach ($block['items'] as $item) {
                $desc .= "  - {$item['label']} (key: {$item['key']})\n";
            }
        }

        return $desc;
    }

    /**
     * Get cell type documentation for AI.
     */
    public static function getCellTypeDocumentation(): string
    {
        return <<<'DOC'
CELL TYPES AND THEIR PROPERTIES:

1. text (default)
   - Just displays the value as text
   - Properties: class (optional CSS class)

2. currency
   - Formats as money: $1,234.56
   - Properties: currency (USD, EUR, etc.), decimals (default: 2)

3. number
   - Formats with thousand separators: 1,234
   - Properties: decimals (default: 0)

4. percentage
   - Displays with % sign: 12.5%
   - Properties: decimals (default: 1)

5. date
   - Formats date values
   - Properties: format (PHP date format, default: m-d-Y)

6. link
   - Clickable link
   - Properties: href_template ("/orders/{id}"), target (_self, _blank)
   - Data can be: string (displayed text) or {data: "text", href: "/url"}

7. badge
   - Status indicator with color
   - Properties: variants ({"completed": "success", "pending": "warning"}), default_variant
   - Variant colors: success, warning, danger, info, secondary, primary

8. image
   - Displays image
   - Properties: width, height, fallback (placeholder URL)
   - Data: URL string or {src: "url", alt: "text"}

9. html
   - Raw HTML rendering (use carefully)
   - Data: HTML string
DOC;
    }

    /**
     * Generate sample data based on the structure.
     */
    public function generateSampleData(): array
    {
        $data = [
            'date' => 'Report for '.date('m-d-Y'),
            'store' => ['name' => 'Sample Store', 'email' => 'info@store.com'],
        ];

        foreach ($this->tables as $table) {
            $sampleRows = [];
            for ($i = 0; $i < 3; $i++) {
                $row = [];
                foreach ($table['columns'] as $col) {
                    $row[$col['key']] = $this->generateSampleCellValue($col, $i);
                }
                $sampleRows[] = $row;
            }
            $data[$table['data_key']] = $sampleRows;
        }

        return $data;
    }

    /**
     * Generate a sample cell value based on column type.
     */
    protected function generateSampleCellValue(array $column, int $index): mixed
    {
        $type = $column['type'] ?? 'text';
        $key = $column['key'] ?? 'value';

        return match ($type) {
            self::TYPE_CURRENCY => [
                'data' => rand(100, 5000) + rand(0, 99) / 100,
                'formatted' => '$'.number_format(rand(100, 5000) + rand(0, 99) / 100, 2),
            ],
            self::TYPE_NUMBER => rand(1, 100),
            self::TYPE_PERCENTAGE => rand(0, 100) + rand(0, 9) / 10,
            self::TYPE_DATE => date('m-d-Y', strtotime("-{$index} days")),
            self::TYPE_LINK => [
                'data' => 'Item #'.($index + 1),
                'href' => '/items/'.($index + 1),
            ],
            self::TYPE_BADGE => [
                'data' => ['Completed', 'Pending', 'Processing'][$index % 3],
                'variant' => ['success', 'warning', 'info'][$index % 3],
            ],
            self::TYPE_IMAGE => [
                'src' => 'https://via.placeholder.com/50',
                'alt' => 'Sample Image',
            ],
            default => 'Sample '.$column['label'].' '.($index + 1),
        };
    }
}

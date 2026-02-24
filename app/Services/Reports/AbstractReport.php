<?php

namespace App\Services\Reports;

use App\Models\Store;
use Carbon\Carbon;

/**
 * Abstract base class for structured reports.
 *
 * Reports consist of:
 * - Logo (optional)
 * - Title and subtitle
 * - One or more tables with typed columns
 * - Summary blocks
 * - Footer
 *
 * Each table can have:
 * - Columns with types (text, currency, link, badge, etc.)
 * - Data rows
 * - Footer/totals row
 */
abstract class AbstractReport
{
    protected Store $store;

    protected Carbon $reportDate;

    protected ?ReportStructure $structure = null;

    protected ReportFieldRegistry $fieldRegistry;

    public function __construct(Store $store, ?Carbon $reportDate = null)
    {
        $this->store = $store;
        $this->reportDate = $reportDate ?? Carbon::yesterday();
        $this->fieldRegistry = new ReportFieldRegistry($store->id);
    }

    /**
     * Get the report type identifier.
     */
    abstract public function getType(): string;

    /**
     * Get the report name.
     */
    abstract public function getName(): string;

    /**
     * Get the default template slug.
     */
    abstract public function getSlug(): string;

    /**
     * Get a description of the report.
     */
    public function getDescription(): string
    {
        return "Report for {$this->store->name} - {$this->reportDate->format('M j, Y')}";
    }

    /**
     * Define the report structure.
     */
    abstract protected function defineStructure(): ReportStructure;

    /**
     * Fetch the actual data for the report.
     */
    abstract public function getData(): array;

    /**
     * Get the report structure.
     */
    public function getStructure(): ReportStructure
    {
        if ($this->structure === null) {
            $this->structure = $this->defineStructure();
        }

        return $this->structure;
    }

    /**
     * Get structure as array (for JSON storage).
     */
    public function toArray(): array
    {
        return $this->getStructure()->toArray();
    }

    /**
     * Get structure as JSON.
     */
    public function toJson(): string
    {
        return $this->getStructure()->toJson();
    }

    /**
     * Get combined structure and data for rendering or AI.
     */
    public function getTemplateContext(): array
    {
        return [
            'structure' => $this->toArray(),
            'data' => $this->getData(),
            'description' => $this->describeForAI(),
        ];
    }

    /**
     * Describe this report for AI.
     */
    public function describeForAI(): string
    {
        return "This is a {$this->getName()} report. ".$this->getStructure()->describeForAI();
    }

    /**
     * Generate a Twig template for this report using AI.
     */
    public function generateTemplate(ReportStructureGenerator $generator): array
    {
        return $generator->generateTemplate($this->getStructure());
    }

    // Structure building helpers

    protected function structure(): ReportStructure
    {
        return new ReportStructure;
    }

    // Column helpers using static methods from ReportStructure

    protected function textColumn(string $key, string $label, array $options = []): array
    {
        return ReportStructure::textColumn($key, $label, $options);
    }

    protected function currencyColumn(string $key, string $label, array $options = []): array
    {
        return ReportStructure::currencyColumn($key, $label, 'USD', $options);
    }

    protected function numberColumn(string $key, string $label, array $options = []): array
    {
        return ReportStructure::numberColumn($key, $label, $options);
    }

    protected function percentageColumn(string $key, string $label, array $options = []): array
    {
        return ReportStructure::percentageColumn($key, $label, $options);
    }

    protected function dateColumn(string $key, string $label, string $format = 'm-d-Y', array $options = []): array
    {
        return ReportStructure::dateColumn($key, $label, $format, $options);
    }

    protected function linkColumn(string $key, string $label, string $hrefTemplate, array $options = []): array
    {
        return ReportStructure::linkColumn($key, $label, $hrefTemplate, $options);
    }

    protected function badgeColumn(string $key, string $label, array $variants = [], array $options = []): array
    {
        return ReportStructure::badgeColumn($key, $label, $variants, $options);
    }

    // Data formatting helpers

    protected function formatCurrency(float|int|null $value): array
    {
        return [
            'data' => $value ?? 0,
            'formatted' => '$'.number_format($value ?? 0, 2),
        ];
    }

    protected function formatNumber(float|int|null $value, int $decimals = 0): array
    {
        return [
            'data' => $value ?? 0,
            'formatted' => number_format($value ?? 0, $decimals),
        ];
    }

    protected function formatPercentage(float|int|null $value, int $decimals = 1): array
    {
        return [
            'data' => $value ?? 0,
            'formatted' => number_format($value ?? 0, $decimals).'%',
        ];
    }

    protected function formatDate(?Carbon $date, string $format = 'm-d-Y'): string
    {
        return $date?->format($format) ?? 'N/A';
    }

    protected function formatLink(string $text, string $href, array $options = []): array
    {
        return [
            'data' => $text,
            'href' => $href,
            'target' => $options['target'] ?? '_self',
        ];
    }

    protected function formatBadge(string $text, string $variant = 'secondary'): array
    {
        return [
            'data' => $text,
            'variant' => $variant,
        ];
    }

    // Utility methods

    public function getStore(): Store
    {
        return $this->store;
    }

    public function getReportDate(): Carbon
    {
        return $this->reportDate;
    }

    protected function getTitleWithDate(string $prefix = ''): string
    {
        $storeName = $this->store->name ?? 'Store';
        $dateStr = $this->reportDate->format('m-d-Y');

        return trim("{$storeName} - {$prefix} for {$dateStr}");
    }

    /**
     * Build a totals row for a table.
     */
    protected function buildTotalsRow(array $items, array $columns): array
    {
        $totals = ['_is_total' => true];

        foreach ($columns as $column) {
            $key = $column['key'];
            $type = $column['type'] ?? 'text';

            if ($type === 'currency' || $type === 'number') {
                $sum = collect($items)->sum(function ($item) use ($key) {
                    $value = $item[$key] ?? 0;

                    return is_array($value) ? ($value['data'] ?? 0) : $value;
                });

                $totals[$key] = $type === 'currency'
                    ? $this->formatCurrency($sum)
                    : $this->formatNumber($sum);
            } else {
                $totals[$key] = $key === array_key_first(array_column($columns, 'key', 'key'))
                    ? 'Total'
                    : '';
            }
        }

        return $totals;
    }
}

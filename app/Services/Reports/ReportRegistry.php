<?php

namespace App\Services\Reports;

use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use ReflectionClass;

/**
 * Registry that scans and lists available report classes.
 *
 * Scans the Email directory for classes extending AbstractReport
 * and provides metadata for UI dropdowns and API endpoints.
 */
class ReportRegistry
{
    protected string $reportDirectory;

    protected string $reportNamespace;

    protected ?Collection $reports = null;

    public function __construct()
    {
        $this->reportDirectory = app_path('Services/Reports/Email');
        $this->reportNamespace = 'App\\Services\\Reports\\Email';
    }

    /**
     * Get all available report classes.
     *
     * @return Collection<int, array{class: string, type: string, name: string, slug: string, description: string}>
     */
    public function getAvailableReports(): Collection
    {
        if ($this->reports !== null) {
            return $this->reports;
        }

        $this->reports = collect();

        if (! File::isDirectory($this->reportDirectory)) {
            return $this->reports;
        }

        $files = File::files($this->reportDirectory);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $file->getFilenameWithoutExtension();
            $fullClass = "{$this->reportNamespace}\\{$className}";

            if (! class_exists($fullClass)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($fullClass);

                // Skip if not a concrete class or doesn't extend AbstractReport
                if ($reflection->isAbstract() || ! $reflection->isSubclassOf(AbstractReport::class)) {
                    continue;
                }

                // Get report metadata by instantiating with dummy data
                $metadata = $this->extractMetadata($fullClass);

                if ($metadata) {
                    $this->reports->push($metadata);
                }
            } catch (\Throwable) {
                // Skip classes that fail to load
                continue;
            }
        }

        return $this->reports;
    }

    /**
     * Get a specific report class by type.
     */
    public function getReportByType(string $type): ?string
    {
        $report = $this->getAvailableReports()->firstWhere('type', $type);

        return $report['class'] ?? null;
    }

    /**
     * Get a specific report class by slug.
     */
    public function getReportBySlug(string $slug): ?string
    {
        $report = $this->getAvailableReports()->firstWhere('slug', $slug);

        return $report['class'] ?? null;
    }

    /**
     * Instantiate a report by type.
     */
    public function makeReport(string $type, Store $store, ?Carbon $reportDate = null): ?AbstractReport
    {
        $class = $this->getReportByType($type);

        if (! $class || ! class_exists($class)) {
            return null;
        }

        return new $class($store, $reportDate);
    }

    /**
     * Instantiate a report by slug.
     */
    public function makeReportBySlug(string $slug, Store $store, ?Carbon $reportDate = null): ?AbstractReport
    {
        $class = $this->getReportBySlug($slug);

        if (! $class || ! class_exists($class)) {
            return null;
        }

        return new $class($store, $reportDate);
    }

    /**
     * Get reports formatted for dropdown selection.
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public function getDropdownOptions(): array
    {
        return $this->getAvailableReports()
            ->map(fn (array $report) => [
                'value' => $report['type'],
                'label' => $report['name'],
                'description' => $report['description'] ?? '',
                'slug' => $report['slug'],
            ])
            ->values()
            ->toArray();
    }

    /**
     * Check if a report type exists.
     */
    public function exists(string $type): bool
    {
        return $this->getReportByType($type) !== null;
    }

    /**
     * Extract metadata from a report class.
     */
    protected function extractMetadata(string $fullClass): ?array
    {
        try {
            // Create a mock store for instantiation
            $mockStore = new Store;
            $mockStore->id = 0;
            $mockStore->name = 'Test Store';

            /** @var AbstractReport $instance */
            $instance = new $fullClass($mockStore);

            return [
                'class' => $fullClass,
                'type' => $instance->getType(),
                'name' => $instance->getName(),
                'slug' => $instance->getSlug(),
                'description' => $this->extractDescription($fullClass),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract description from class docblock.
     */
    protected function extractDescription(string $fullClass): string
    {
        try {
            $reflection = new ReflectionClass($fullClass);
            $docComment = $reflection->getDocComment();

            if (! $docComment) {
                return '';
            }

            // Remove /** and */ and * prefixes
            $docComment = preg_replace('/^\s*\/\*\*|\*\/\s*$/m', '', $docComment);
            $docComment = preg_replace('/^\s*\*\s?/m', '', $docComment);

            // Get first paragraph
            $lines = array_filter(explode("\n", trim($docComment)));
            $description = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if (str_starts_with($line, '@')) {
                    break;
                }
                if ($line === '') {
                    break;
                }
                $description[] = $line;
            }

            return implode(' ', $description);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Clear the cached reports.
     */
    public function clearCache(): void
    {
        $this->reports = null;
    }
}

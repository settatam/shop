<?php

namespace App\Widget;

use InvalidArgumentException;

class Chart extends Widget
{
    protected string $component = 'Chart';

    protected ?string $chartType = null;

    /** @var array<string> */
    protected array $labels = [];

    /** @var array<string, mixed> */
    protected array $chartOptions = [];

    /** @var array<string, mixed> */
    protected array $styles = [];

    /** @var array<string, mixed> */
    protected array $plugins = [];

    /** @var array<string, mixed> */
    protected array $legend = [];

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function chartType(?array $filter, array $filteredData): ?string
    {
        return $this->chartType;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string>
     */
    public function labels(?array $filter, array $filteredData): array
    {
        return $this->labels;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string, mixed>
     */
    public function chartData(?array $filter, array $filteredData): array
    {
        return [
            'labels' => $this->labels($filter, $filteredData),
            'datasets' => $this->datasets($filter, $filteredData),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<array<string, mixed>>
     */
    public function datasets(?array $filter, array $filteredData): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string, mixed>
     */
    public function plugins(?array $filter, array $filteredData): array
    {
        $plugins = [];

        data_set($plugins, 'datalabels.display', $this->displayDataLabels($filter, $filteredData));

        return array_merge($plugins, $this->plugins);
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string, mixed>
     */
    public function legend(?array $filter, array $filteredData): array
    {
        return array_merge([], $this->legend);
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string, mixed>
     */
    public function chartOptions(?array $filter, array $filteredData): array
    {
        $options = [];

        data_set($options, 'plugins', (object) $this->plugins($filter, $filteredData));
        data_set($options, 'legend', (object) $this->legend($filter, $filteredData));

        return array_merge($options, $this->chartOptions);
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function displayDataLabels(?array $filter, array $filteredData): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string, mixed>
     */
    public function styles(?array $filter, array $filteredData): array
    {
        return $this->styles;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string, mixed>
     */
    public function config(?array $filter, array $filteredData = []): array
    {
        $chartType = $this->chartType($filter, $filteredData);
        if (empty($chartType)) {
            throw new InvalidArgumentException('chartType cannot be empty');
        }

        return [
            'chartType' => $chartType,
            'data' => [
                'styles' => $this->styles($filter, $filteredData),
                'chartData' => $this->chartData($filter, $filteredData),
                'options' => $this->chartOptions($filter, $filteredData),
            ],
        ];
    }
}

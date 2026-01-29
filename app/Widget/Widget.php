<?php

namespace App\Widget;

use App\Models\User;

abstract class Widget
{
    protected int $cacheFor = 0;

    protected bool $beta = false;

    protected string $title = 'Widget';

    protected ?string $icon = null;

    protected ?string $description = null;

    protected string $component = 'Empty';

    protected ?string $explore = null;

    /** @var array<string, mixed> */
    protected array $options = [];

    /** @var array<string, mixed>|null */
    protected ?array $filter = null;

    /** @var array<string, mixed> */
    protected array $data = [];

    protected string $sortBy = 'id';

    protected string $sortDirection = 'desc';

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function config(?array $filter, array $data): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return $this->title;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $data
     */
    public function component(?array $filter, array $data): string
    {
        return $this->component;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function description(?array $filter, array $filteredData): ?string
    {
        return $this->description;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    protected function icon(?array $filter, array $filteredData): ?string
    {
        return $this->icon;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    protected function explore(?array $filter, array $filteredData): ?string
    {
        return $this->explore;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    protected function exploreLabel(?array $filter, array $filteredData): string
    {
        return 'Explore';
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function exportable(?array $filter, array $filteredData): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function options(?array $filter, array $data): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function sortBy(?array $filter): string
    {
        $sortBy = data_get($filter, 'sortBy');
        if ($sortBy) {
            return $sortBy;
        }

        return $this->sortBy;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function sortDirection(?array $filter): string
    {
        $sortDirection = data_get($filter, 'sortDirection');
        if ($sortDirection) {
            return $sortDirection;
        }

        return $this->sortDirection;
    }

    public function defersExport(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    protected function footer(?array $filter, array $filteredData): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function authorized(User $user, string $action = 'view', ?array $filter = null): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array<mixed>
     */
    public function actions(?array $filter): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function buildFilters(?array $filter, array $data): ?array
    {
        return $filter;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed>
     */
    public function data(?array $filter): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function export(?array $filter, array $filteredData): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function exportTitle(?array $filter, array $filteredData): string
    {
        return $this->title;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function reload(?array $filter, array $filteredData): string
    {
        return (string) time();
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function render(?array $filter = null, array $data = []): array
    {
        $data = $this->data($filter);

        return array_merge([
            'widget' => get_class($this),
            'title' => $this->title($filter),
            'description' => $this->description($filter, $data),
            'icon' => $this->icon($filter, $data),
            'component' => $this->component($filter, $data),
            'filter' => $filter,
            'filters' => $this->buildFilters($filter, $data),
            'explore' => $this->explore($filter, $data),
            'exploreLabel' => $this->exploreLabel($filter, $data),
            'footer' => $this->footer($filter, $data),
            'exportable' => $this->exportable($filter, $data),
            'exportable_title' => $this->exportTitle($filter, $data),
            'options' => $this->options($filter, $data),
            'actions' => $this->actions($filter),
            'export' => $this->export($filter, $data),
            'reload' => $this->reload($filter, $data),
        ], $this->config($filter, $data));
    }
}

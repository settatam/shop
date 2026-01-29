<?php

namespace App\Widget;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class Table extends Widget
{
    protected string $title = 'Table Widget';

    protected string $component = 'Table';

    protected bool $striped = true;

    protected bool $hover = true;

    protected bool $allowShowAll = true;

    protected bool $showAll = false;

    protected bool $fixed = false;

    protected bool $responsive = true;

    protected bool $stickyHeader = true;

    protected bool $stacked = false;

    protected bool $hasCheckBox = false;

    protected bool $isSearchable = false;

    protected bool $shouldChangeStatus = false;

    protected bool $showPagination = true;

    /** @var LengthAwarePaginator<mixed>|null */
    protected ?LengthAwarePaginator $paginatedData = null;

    protected string $noDataMessage = 'You do not have any data.';

    protected bool $hasFooter = false;

    /** @var array<string, mixed>|null */
    protected ?array $filter = null;

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $data
     */
    public function __construct(?array $filter = null, array $data = [])
    {
        $this->filter = $filter;
    }

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>|string>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<mixed>
     */
    public function items(?array $filter, array $filteredData): array
    {
        return data_get($filteredData, 'items', []);
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string, mixed>
     */
    protected function tableOptions(?array $filter, array $filteredData): array
    {
        return [
            'fixed' => $this->fixed($filter, $filteredData),
            'stickyHeader' => $this->stickyHeader($filter, $filteredData),
            'stacked' => $this->stacked($filter, $filteredData),
            'responsive' => $this->responsive($filter, $filteredData),
            'striped' => $this->striped($filter, $filteredData),
            'hover' => $this->hover($filter, $filteredData),
            'fieldWidths' => $this->fieldWidths($filter, $filteredData),
            'perPage' => $this->perPage($filter, $filteredData),
            'allowShowAll' => $this->allowShowAll($filter, $filteredData),
            'hasCheckBox' => $this->hasCheckBox(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string, mixed>
     */
    public function config(?array $filter, array $filteredData = []): array
    {
        $config = [
            'data' => [
                'fields' => $this->fields(),
                'options' => $this->tableOptions($filter, $filteredData),
                'items' => $this->items($filter, $filteredData),
                'footerColumns' => $this->footerColumns(),
            ],
        ];

        $config['hasFooter'] = $this->hasFooter();
        $config['hasCheckBox'] = $this->hasCheckBox();
        $config['isSearchable'] = $this->isSearchable();
        $config['pagination'] = $this->pagination();
        $config['noData'] = $this->noData($filter);
        $config['filter'] = $this->tableFilter($filter, $filteredData);

        $config['fields'] = collect($this->fields())
            ->map(function ($data) use (&$config) {
                if (is_string($data)) {
                    return [
                        $key = $data,
                        Str::headline($key),
                        false,
                    ];
                }

                $key = data_get($data, 'key');
                $field = [
                    $key,
                    data_get($data, 'label', Str::headline($key)),
                    data_get($data, 'sortable', false),
                ];

                if ($html = data_get($data, 'html')) {
                    if (is_array($html)) {
                        if (data_get($data, 'html.field')) {
                            $config['data']['options']['htmlFormattedFields'][] = $key;
                        }
                        if (data_get($data, 'html.header')) {
                            $config['data']['options']['htmlFormattedHeaders'][] = $key;
                        }
                    } else {
                        $config['data']['options']['htmlFormattedFields'][] = $key;
                        $config['data']['options']['htmlFormattedHeaders'][] = $key;
                    }
                }

                if ($width = data_get($data, 'width')) {
                    $config['data']['options']['fieldWidths'][$key] = $width;
                }

                return $field;
            })
            ->toArray();

        return $config;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string, mixed>
     */
    protected function fieldWidths(?array $filter, array $filteredData): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    protected function fixed(?array $filter, array $filteredData): bool
    {
        return $this->fixed;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    protected function stacked(?array $filter, array $filteredData): bool
    {
        return $this->stacked;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    protected function responsive(?array $filter, array $filteredData): bool
    {
        return $this->responsive;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    protected function stickyHeader(?array $filter, array $filteredData): bool
    {
        return $this->stickyHeader;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    protected function striped(?array $filter, array $filteredData): bool
    {
        return $this->striped;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    protected function hover(?array $filter, array $filteredData): bool
    {
        return $this->hover;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function perPage(?array $filter, array $filteredData): int
    {
        return (int) data_get($filter, 'per_page', 15);
    }

    public function totalRows(): int
    {
        return $this->paginatedData?->total() ?? 0;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function allowShowAll(?array $filter, array $filteredData): bool
    {
        return $this->allowShowAll;
    }

    public function hasCheckBox(): bool
    {
        return $this->hasCheckBox;
    }

    public function isSearchable(): bool
    {
        return $this->isSearchable;
    }

    public function shouldChangeStatus(): bool
    {
        return $this->shouldChangeStatus;
    }

    public function showPagination(): bool
    {
        return $this->showPagination;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function noData(?array $filter): string
    {
        return $this->noDataMessage;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>|null  $filteredData
     * @return array<string>
     */
    public function exportExceptions(?array $filter, ?array $filteredData = null): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>|null  $filteredData
     * @return array<string, mixed>
     */
    public function exportAdditions(?array $filter, ?array $filteredData = null): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function pagination(): array
    {
        if (! $this->paginatedData) {
            return [
                'total' => 0,
                'per_page' => 15,
                'current_page' => 1,
                'from' => 0,
                'to' => 0,
                'first_page_url' => '',
                'last_page_url' => '',
                'path' => '',
                'links' => [],
                'show_pagination' => $this->showPagination(),
            ];
        }

        return [
            'total' => $this->paginatedData->total(),
            'per_page' => $this->paginatedData->perPage(),
            'current_page' => $this->paginatedData->currentPage(),
            'from' => (($this->paginatedData->currentPage() - 1) * $this->paginatedData->perPage()) + 1,
            'to' => min(
                $this->paginatedData->currentPage() * $this->paginatedData->perPage(),
                $this->paginatedData->total()
            ),
            'first_page_url' => $this->paginatedData->url(1),
            'last_page_url' => $this->paginatedData->url($this->paginatedData->lastPage()),
            'path' => $this->paginatedData->path(),
            'links' => $this->paginatedData->linkCollection()->toArray(),
            'show_pagination' => $this->showPagination(),
        ];
    }

    public function hasFooter(): bool
    {
        return $this->hasFooter;
    }

    /**
     * @return array<string, mixed>
     */
    protected function footerColumns(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string, mixed>|null
     */
    protected function tableFilter(?array $filter, array $filteredData): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function exportTitle(?array $filter, array $filteredData): string
    {
        return $this->title($filter);
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function export(?array $filter, array $filteredData): ?string
    {
        return null;
    }
}

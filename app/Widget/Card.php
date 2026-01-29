<?php

namespace App\Widget;

class Card extends Widget
{
    protected string $header = 'Example Header';

    protected string $body = 'Example Body';

    /** @var array<array<string, string>> */
    protected array $links = [];

    protected bool $hasFooter = false;

    protected string $component = 'Card';

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed>
     */
    public function data(?array $filter): array
    {
        return parent::data($filter);
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function config(?array $filter, array $data = []): array
    {
        return [
            'header' => $this->header($filter, $data),
            'body' => $this->body($filter, $data),
            'links' => $this->links($filter, $data),
            'hasFooter' => $this->hasFooter($filter, $data),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function hasFooter(?array $filter, array $filteredData): bool
    {
        return $this->hasFooter;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function header(?array $filter, array $filteredData): string
    {
        return $this->header;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function body(?array $filter, array $filteredData): string
    {
        return $this->body;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<array<string, string>>
     */
    public function links(?array $filter, array $filteredData): array
    {
        return $this->links;
    }
}

<?php

namespace App\Widget;

use Illuminate\Http\Request;

abstract class Form
{
    protected ?string $button = null;

    /** @var array<mixed> */
    protected array $fields = [];

    protected string $action = '';

    protected string $method = '';

    /** @var array<mixed> */
    protected array $formGroups = [];

    protected bool $shouldConfirm = false;

    protected string $name = 'Form';

    protected string $component = 'FormWidget';

    protected bool $hasButtons = true;

    protected int $numberOfColumns = 2;

    protected ?int $storeId = null;

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array<mixed>
     */
    public function formGroups(?array $filter, mixed $filteredData): array
    {
        return $this->formGroups;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function method(?array $filter, mixed $filteredData): string
    {
        return $this->method;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function action(?array $filter, mixed $filteredData): string
    {
        return $this->action;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function shouldConfirm(?array $filter, mixed $filteredData): bool
    {
        return $this->shouldConfirm;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function type(?array $filter, mixed $filteredData): string
    {
        return get_class($this);
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function name(?array $filter, mixed $filteredData): string
    {
        return $this->name;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array<array<string, mixed>>
     */
    public function buttons(?array $filter, mixed $filteredData): array
    {
        return [
            [
                'type' => 'button',
                'label' => 'Submit',
                'classes' => '',
                'id' => '',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function process(Request $request, ?int $id = null): array
    {
        $input = $request->input();
        $formData = [];

        foreach ($input['formGroups'] ?? [] as $data) {
            $formField = $data['fields'] ?? [];
            $fieldType = data_get($formField, 'type');

            if ($fieldType === 'checkbox' || $fieldType === 'radio') {
                $formData[$formField['name']] = $formField['selected'];
            } elseif ($fieldType === 'input') {
                $formData[$formField['name']] = $formField['value'];
            }
        }

        return $formData;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function data(?array $filter): mixed
    {
        $model = data_get($filter, 'type');
        if ($model && class_exists($model)) {
            $obj = new $model;
            $id = data_get($filter, 'id');
            if ($id) {
                return $obj->find($id);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function component(?array $filter, mixed $filteredData): string
    {
        return $this->component;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function hasButtons(?array $filter, mixed $filteredData): bool
    {
        return $this->hasButtons;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed>
     */
    public function config(?array $filter, mixed $filteredData): array
    {
        return [];
    }

    public function numberOfColumns(): int
    {
        return $this->numberOfColumns;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function storeId(?array $filter, mixed $filteredData): ?int
    {
        $storeId = data_get($filter, 'store_id');
        if ($storeId) {
            return (int) $storeId;
        }

        return $this->storeId;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed>
     */
    public function render(?array $filter = null, mixed $filteredData = null): array
    {
        if (! $filteredData) {
            $filteredData = $this->data($filter);
        }

        return array_merge([
            'formGroups' => $this->formGroups($filter, $filteredData),
            'method' => $this->method($filter, $filteredData),
            'action' => $this->action($filter, $filteredData),
            'buttons' => $this->buttons($filter, $filteredData),
            'shouldConfirm' => $this->shouldConfirm($filter, $filteredData),
            'name' => $this->name($filter, $filteredData),
            'type' => $this->type($filter, $filteredData),
            'storeId' => $this->storeId($filter, $filteredData),
            'component' => $this->component($filter, $filteredData),
            'hasButtons' => $this->hasButtons($filter, $filteredData),
        ], $this->config($filter, $filteredData));
    }
}

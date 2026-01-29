<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusTransition extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_status_id',
        'to_status_id',
        'name',
        'description',
        'conditions',
        'required_fields',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'required_fields' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Get the source status.
     */
    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'from_status_id');
    }

    /**
     * Get the target status.
     */
    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'to_status_id');
    }

    /**
     * Check if the transition is allowed based on conditions.
     *
     * @param  array<string, mixed>  $context
     */
    public function isAllowed(array $context = []): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        $conditions = $this->conditions ?? [];

        foreach ($conditions as $field => $requirement) {
            if (! $this->checkCondition($field, $requirement, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check a single condition.
     */
    protected function checkCondition(string $field, mixed $requirement, array $context): bool
    {
        $value = $context[$field] ?? null;

        // If requirement is an array with operator
        if (is_array($requirement)) {
            $operator = $requirement['operator'] ?? 'equals';
            $expected = $requirement['value'] ?? null;

            return match ($operator) {
                'equals' => $value === $expected,
                'not_equals' => $value !== $expected,
                'greater_than' => $value > $expected,
                'less_than' => $value < $expected,
                'in' => in_array($value, (array) $expected),
                'not_in' => ! in_array($value, (array) $expected),
                'is_set' => $value !== null && $value !== '',
                'is_not_set' => $value === null || $value === '',
                default => true,
            };
        }

        // Simple equality check
        return $value === $requirement;
    }

    /**
     * Get the required fields for this transition.
     *
     * @return array<string, array{type: string, label: string, required: bool}>
     */
    public function getRequiredFieldsConfig(): array
    {
        return $this->required_fields ?? [];
    }

    /**
     * Get the display name for this transition.
     */
    public function getDisplayName(): string
    {
        if ($this->name) {
            return $this->name;
        }

        return "Move to {$this->toStatus->name}";
    }

    /**
     * Scope to get enabled transitions.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}

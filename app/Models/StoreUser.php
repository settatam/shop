<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreUser extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'role_id',
        'user_id',
        'store_id',
        'is_owner',
        'store_group_id',
        'status',
        'first_name',
        'last_name',
        'email',
        'token',
        'temp_password',
        'created_by',
        'store_location_id',
        'default_warehouse_id',
    ];

    protected function casts(): array
    {
        return [
            'is_owner' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function isInvitePending(): bool
    {
        return $this->status === 'invite sent';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the store user has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->role?->hasPermission($permission) ?? false;
    }

    /**
     * Check if the store user has any of the given permissions.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return $this->role?->hasAnyPermission($permissions) ?? false;
    }

    /**
     * Check if the store user has all of the given permissions.
     */
    public function hasAllPermissions(array $permissions): bool
    {
        return $this->role?->hasAllPermissions($permissions) ?? false;
    }

    /**
     * Check if the store user is the owner.
     */
    public function isOwner(): bool
    {
        return $this->is_owner || ($this->role?->isOwner() ?? false);
    }

    /**
     * Get all permissions for this store user.
     */
    public function getPermissions(): array
    {
        return $this->role?->getExpandedPermissions() ?? [];
    }

    protected function getActivityMap(): array
    {
        return [
            'create' => Activity::TEAM_INVITE,
            'update' => Activity::TEAM_UPDATE,
            'delete' => Activity::TEAM_REMOVE,
        ];
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'user_id', 'store_id', 'role_id', 'is_owner', 'status', 'email'];
    }

    protected function getActivityDescription(string $action): string
    {
        $identifier = $this->email ?? $this->full_name ?? "#{$this->id}";

        return match ($action) {
            'create' => "Team member {$identifier} was invited",
            'update' => "Team member {$identifier} was updated",
            'delete' => "Team member {$identifier} was removed",
            default => "{$action} performed on team member {$identifier}",
        };
    }

    protected function getActivityIdentifier(): string
    {
        return $this->email ?? $this->full_name ?? "#{$this->id}";
    }
}

<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use BelongsToStore, HasFactory, LogsActivity, SoftDeletes;

    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const MANAGER = 'manager';

    public const STAFF = 'staff';

    public const VIEWER = 'viewer';

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'description',
        'permissions',
        'is_default',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_default' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function storeUsers(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    /**
     * Check if the role has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        if (empty($this->permissions)) {
            return false;
        }

        // Check for wildcard (owner has all permissions)
        if (in_array('*', $this->permissions)) {
            return true;
        }

        // Check for exact match
        if (in_array($permission, $this->permissions)) {
            return true;
        }

        // Check for category wildcard (e.g., "products.*" matches "products.view")
        $category = explode('.', $permission)[0] ?? null;
        if ($category && in_array($category.'.*', $this->permissions)) {
            return true;
        }

        return false;
    }

    /**
     * Check if the role has any of the given permissions.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the role has all of the given permissions.
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Grant a permission to the role.
     */
    public function grantPermission(string $permission): self
    {
        $permissions = $this->permissions ?? [];

        if (! in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->permissions = $permissions;
            $this->save();
        }

        return $this;
    }

    /**
     * Revoke a permission from the role.
     */
    public function revokePermission(string $permission): self
    {
        $permissions = $this->permissions ?? [];
        $permissions = array_values(array_filter($permissions, fn ($p) => $p !== $permission));
        $this->permissions = $permissions;
        $this->save();

        return $this;
    }

    /**
     * Sync permissions (replace all permissions).
     */
    public function syncPermissions(array $permissions): self
    {
        $this->permissions = array_values(array_unique($permissions));
        $this->save();

        return $this;
    }

    /**
     * Get all expanded permissions (resolve wildcards).
     */
    public function getExpandedPermissions(): array
    {
        $permissions = $this->permissions ?? [];

        if (in_array('*', $permissions)) {
            return Activity::getAllSlugs();
        }

        $expanded = [];
        foreach ($permissions as $permission) {
            if (str_ends_with($permission, '.*')) {
                $category = str_replace('.*', '', $permission);
                $expanded = array_merge($expanded, Activity::getByCategory($category));
            } else {
                $expanded[] = $permission;
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Check if this is the owner role.
     */
    public function isOwner(): bool
    {
        return $this->slug === self::OWNER;
    }

    /**
     * Check if this is a system role (cannot be deleted).
     */
    public function isSystemRole(): bool
    {
        return $this->is_system || in_array($this->slug, [self::OWNER]);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    /**
     * Create default roles for a store.
     */
    public static function createDefaultRoles(int $storeId): void
    {
        $presets = Activity::getRolePresets();

        foreach ($presets as $key => $preset) {
            self::create([
                'store_id' => $storeId,
                'name' => $preset['name'],
                'slug' => $preset['slug'],
                'description' => $preset['description'],
                'permissions' => $preset['permissions'],
                'is_default' => $key === 'staff',
                'is_system' => $key === 'owner',
            ]);
        }
    }

    protected function getActivityPrefix(): string
    {
        return 'roles';
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'name', 'slug', 'description', 'is_default', 'is_system'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->name ?? "#{$this->id}";
    }
}

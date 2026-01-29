<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'current_store_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_users')
            ->withPivot(['role_id', 'status', 'first_name', 'last_name', 'email'])
            ->withTimestamps();
    }

    public function ownedStores(): HasMany
    {
        return $this->hasMany(Store::class, 'user_id');
    }

    public function storeUsers(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    public function currentStore(): ?Store
    {
        return app(\App\Services\StoreContext::class)->getCurrentStore();
    }

    public function hasAccessToStore(int $storeId): bool
    {
        return $this->stores()->where('stores.id', $storeId)->exists()
            || $this->ownedStores()->where('id', $storeId)->exists();
    }

    /**
     * Get the StoreUser for the current store context.
     */
    public function currentStoreUser(): ?StoreUser
    {
        $store = $this->currentStore();
        if (! $store) {
            return null;
        }

        return $this->storeUsers()
            ->where('store_id', $store->id)
            ->with('role')
            ->first();
    }

    /**
     * Check if the user has a specific permission in the current store.
     */
    public function hasPermission(string $permission): bool
    {
        $storeUser = $this->currentStoreUser();

        return $storeUser?->hasPermission($permission) ?? false;
    }

    /**
     * Check if the user has any of the given permissions in the current store.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        $storeUser = $this->currentStoreUser();

        return $storeUser?->hasAnyPermission($permissions) ?? false;
    }

    /**
     * Check if the user has all of the given permissions in the current store.
     */
    public function hasAllPermissions(array $permissions): bool
    {
        $storeUser = $this->currentStoreUser();

        return $storeUser?->hasAllPermissions($permissions) ?? false;
    }

    /**
     * Check if the user is the owner of the current store.
     */
    public function isStoreOwner(): bool
    {
        $store = $this->currentStore();
        if (! $store) {
            return false;
        }

        // Check if user owns the store directly
        if ($store->user_id === $this->id) {
            return true;
        }

        // Check if user has owner role
        return $this->currentStoreUser()?->isOwner() ?? false;
    }

    /**
     * Get all permissions for the current store.
     */
    public function getPermissions(): array
    {
        return $this->currentStoreUser()?->getPermissions() ?? [];
    }
}

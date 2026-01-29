<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\HasAddresses;
use App\Traits\HasNotes;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Scout\Searchable;

class Customer extends Authenticatable
{
    use BelongsToStore, HasAddresses, HasFactory, HasNotes, LogsActivity, Notifiable, Searchable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'company_name',
        'email',
        'store_id',
        'lead_source_id',
        'accepts_marketing',
        'is_active',
        'password',
        'notify',
        'city',
        'state',
        'country_id',
        'state_id',
        'phone_number',
        'address',
        'address2',
        'zip',
        'user_id',
        'ethnicity',
        'photo',
        'additional_fields',
        'number_of_sales',
        'number_of_buys',
        'last_sales_date',
        'phone_verified_at',
        'portal_invite_token',
        'portal_invite_sent_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'full_name',
        'display_name',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'accepts_marketing' => 'boolean',
            'is_active' => 'boolean',
            'notify' => 'boolean',
            'additional_fields' => 'array',
            'last_sales_date' => 'date',
            'phone_verified_at' => 'datetime',
            'portal_invite_sent_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class);
    }

    public function vendorRepairs(): HasMany
    {
        return $this->hasMany(Repair::class, 'vendor_id');
    }

    public function vendorMemos(): HasMany
    {
        return $this->hasMany(Memo::class, 'vendor_id');
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }

    public function idFront(): HasOne
    {
        return $this->hasOne(CustomerDocument::class)
            ->where('type', CustomerDocument::TYPE_ID_FRONT)
            ->latest();
    }

    public function idBack(): HasOne
    {
        return $this->hasOne(CustomerDocument::class)
            ->where('type', CustomerDocument::TYPE_ID_BACK)
            ->latest();
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->first_name || $this->last_name) {
            return $this->full_name;
        }

        return $this->email ?? 'Guest';
    }

    protected function getActivityPrefix(): string
    {
        return 'customers';
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'first_name', 'last_name', 'email', 'is_active'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->full_name ?: ($this->email ?? "#{$this->id}");
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'city' => $this->city,
            'store_id' => $this->store_id,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed();
    }
}

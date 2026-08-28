<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantCustomer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'last_activity_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'service_consent_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'publication_consent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function possibleDuplicate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'possible_duplicate_of_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(TenantReminder::class, 'customer_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(TenantClientToken::class, 'customer_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(TenantRequest::class, 'customer_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(TenantAppointment::class, 'customer_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TenantMessage::class, 'customer_id');
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(TenantPushSubscription::class, 'customer_id');
    }
}

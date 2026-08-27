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
        return ['last_activity_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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

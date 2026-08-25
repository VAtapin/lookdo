<?php

namespace App\Models;

use App\Support\LocalizesJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use LocalizesJson;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'description' => 'array', 'badge_text' => 'array', 'price_monthly' => 'decimal:2', 'price_yearly' => 'decimal:2', 'is_active' => 'boolean', 'is_public' => 'boolean', 'stripe_synced_at' => 'datetime'];
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}

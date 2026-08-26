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
        return ['name' => 'array', 'description' => 'array', 'badge_text' => 'array', 'prices' => 'array', 'price_monthly' => 'decimal:2', 'price_yearly' => 'decimal:2', 'is_active' => 'boolean', 'is_public' => 'boolean', 'stripe_synced_at' => 'datetime'];
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function priceFor(string $currency, string $cycle): ?float
    {
        $currency = strtoupper($currency);
        $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
        $configured = $this->prices[$currency][$cycle] ?? null;
        if ($configured !== null && $configured !== '') {
            return (float) $configured;
        }
        if ($currency === strtoupper($this->currency)) {
            $legacy = $cycle === 'yearly' ? $this->price_yearly : $this->price_monthly;

            return $legacy === null ? null : (float) $legacy;
        }

        return null;
    }

    public function priceMatrix(): array
    {
        $matrix = [];
        foreach (['EUR', 'RUB', 'UAH'] as $currency) {
            $matrix[$currency] = [
                'monthly' => $this->priceFor($currency, 'monthly'),
                'yearly' => $this->priceFor($currency, 'yearly'),
            ];
        }

        return $matrix;
    }
}

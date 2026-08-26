<?php

namespace App\Models;

use App\Support\LocalizesJson;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Plan extends Model
{
    use LocalizesJson;

    protected $guarded = [];

    protected $appends = ['image_url'];

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

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->image_path
            ? Storage::disk('public')->url($this->image_path)
            : null);
    }

    public function stripeImageUrl(): ?string
    {
        if (! $this->image_url) {
            return null;
        }

        return str_starts_with($this->image_url, 'http://') || str_starts_with($this->image_url, 'https://')
            ? $this->image_url
            : rtrim((string) config('app.url'), '/').'/'.ltrim($this->image_url, '/');
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['onboarding_completed_at' => 'datetime', 'last_activity_at' => 'datetime'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')->withPivot('role')->withTimestamps();
    }

    public function profile(): HasOne
    {
        return $this->hasOne(TenantProfile::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function primaryDomain(): BelongsTo
    {
        return $this->belongsTo(TenantDomain::class, 'primary_domain_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function businessProfile(): HasOne
    {
        return $this->hasOne(TenantBusinessProfile::class);
    }

    public function smsMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }

    public function imageCreditPurchases(): HasMany
    {
        return $this->hasMany(ImageCreditPurchase::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(TenantService::class);
    }

    public function portfolioItems(): HasMany
    {
        return $this->hasMany(TenantPortfolioItem::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(TenantCustomer::class);
    }

    public function clientTokens(): HasMany
    {
        return $this->hasMany(TenantClientToken::class);
    }

    public function appRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(TenantAppointment::class);
    }

    public function hasActiveSubscription(): bool
    {
        $subscription = $this->currentSubscription;

        return (bool) $subscription && (
            $subscription->complimentary
            || in_array($subscription->status, ['active', 'trialing', 'complimentary'], true)
        );
    }
}

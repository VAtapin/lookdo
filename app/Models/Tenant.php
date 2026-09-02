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

    protected $appends = ['manual_access_active', 'manual_access_days_remaining'];

    protected function casts(): array
    {
        return [
            'onboarding_completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'manual_access_until' => 'datetime',
        ];
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
        return $this->hasOne(Subscription::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('status', '!=', 'superseded'));
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

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(TenantPushSubscription::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TenantMessage::class);
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(TenantWorkingHour::class);
    }

    public function calendarBlocks(): HasMany
    {
        return $this->hasMany(TenantCalendarBlock::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(TenantReminder::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(TenantReview::class);
    }

    public function socialDrafts(): HasMany
    {
        return $this->hasMany(TenantSocialDraft::class);
    }

    public function socialConnections(): HasMany
    {
        return $this->hasMany(TenantSocialConnection::class);
    }

    public function socialProviderConfigs(): HasMany
    {
        return $this->hasMany(TenantSocialProviderConfig::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(TenantResource::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(TenantSegment::class);
    }

    public function hasActiveSubscription(): bool
    {
        if ($this->hasManualAccess()) {
            return true;
        }

        $subscription = $this->currentSubscription;

        return (bool) $subscription?->grantsAccess();
    }

    public function hasManualAccess(): bool
    {
        return $this->manual_access_until !== null && $this->manual_access_until->isFuture();
    }

    public function getManualAccessActiveAttribute(): bool
    {
        return $this->hasManualAccess();
    }

    public function getManualAccessDaysRemainingAttribute(): int
    {
        if (! $this->hasManualAccess()) {
            return 0;
        }

        return max(1, (int) ceil(now()->diffInSeconds($this->manual_access_until) / 86400));
    }
}

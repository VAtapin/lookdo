<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $guarded = [];

    protected $appends = ['access_active', 'trial_active', 'trial_days_remaining'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'current_period_start' => 'datetime', 'current_period_end' => 'datetime', 'cancel_at_period_end' => 'boolean', 'complimentary' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function isTrialActive(): bool
    {
        if ($this->status !== 'trialing') {
            return false;
        }

        $endsAt = $this->trialEndsAt();

        return $endsAt !== null && $endsAt->isFuture();
    }

    public function isPaidAccess(): bool
    {
        return $this->complimentary || in_array($this->status, ['active', 'complimentary'], true);
    }

    public function grantsAccess(): bool
    {
        return $this->isPaidAccess() || $this->isTrialActive();
    }

    public function trialEndsAt(): ?CarbonInterface
    {
        if ($this->current_period_end) {
            return $this->current_period_end;
        }

        $trialDays = (int) ($this->relationLoaded('plan')
            ? $this->plan?->trial_days
            : $this->plan()->value('trial_days'));

        return $trialDays > 0 && $this->started_at
            ? $this->started_at->copy()->addDays($trialDays)
            : null;
    }

    protected function accessActive(): Attribute
    {
        return Attribute::get(fn (): bool => $this->grantsAccess());
    }

    protected function trialActive(): Attribute
    {
        return Attribute::get(fn (): bool => $this->isTrialActive());
    }

    protected function trialDaysRemaining(): Attribute
    {
        return Attribute::get(function (): int {
            if ($this->status !== 'trialing') {
                return 0;
            }

            $endsAt = $this->trialEndsAt();
            if (! $endsAt || ! $endsAt->isFuture()) {
                return 0;
            }

            return max(1, (int) ceil(now()->diffInSeconds($endsAt) / 86400));
        });
    }
}

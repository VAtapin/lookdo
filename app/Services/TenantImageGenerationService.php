<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TenantImageGenerationService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @return array{free_limit:int,free_used:int,remaining_free:int,credits:int,unit_price:float,currency:string,can_generate:bool,payment_required:bool} */
    public function status(Tenant $tenant): array
    {
        $profile = $tenant->profile()->firstOrCreate();
        $freeLimit = max(0, (int) $this->entitlements->get($tenant, 'social_image_free_generations', 3));
        $freeUsed = min($freeLimit, max(0, (int) $profile->image_generation_free_used));
        $credits = max(0, (int) $profile->image_generation_credits);

        $subscriptionActive = $tenant->hasActiveSubscription();

        return [
            'free_limit' => $freeLimit,
            'free_used' => $freeUsed,
            'remaining_free' => max(0, $freeLimit - $freeUsed),
            'credits' => $credits,
            'unit_price' => max(0.01, (int) $this->entitlements->get($tenant, 'social_image_credit_price_cents', 100) / 100),
            'currency' => 'EUR',
            'can_generate' => $subscriptionActive && ($freeUsed < $freeLimit || $credits > 0),
            'payment_required' => ! $subscriptionActive,
        ];
    }

    /** @return array{type:string} */
    public function reserve(Tenant $tenant): array
    {
        return DB::transaction(function () use ($tenant): array {
            $profile = $tenant->profile()->lockForUpdate()->firstOrCreate();
            $freeLimit = max(0, (int) $this->entitlements->get($tenant, 'social_image_free_generations', 3));
            $freeUsed = max(0, (int) $profile->image_generation_free_used);

            if ($freeUsed < $freeLimit) {
                $profile->increment('image_generation_free_used');

                return ['type' => 'free'];
            }

            if ((int) $profile->image_generation_credits < 1) {
                throw new RuntimeException('IMAGE_CREDIT_REQUIRED');
            }

            $profile->decrement('image_generation_credits');

            return ['type' => 'credit'];
        });
    }

    /** @param array{type:string} $reservation */
    public function release(Tenant $tenant, array $reservation): void
    {
        DB::transaction(function () use ($tenant, $reservation): void {
            $profile = $tenant->profile()->lockForUpdate()->firstOrCreate();
            if ($reservation['type'] === 'free' && (int) $profile->image_generation_free_used > 0) {
                $profile->decrement('image_generation_free_used');
            }
            if ($reservation['type'] === 'credit') {
                $profile->increment('image_generation_credits');
            }
        });
    }
}

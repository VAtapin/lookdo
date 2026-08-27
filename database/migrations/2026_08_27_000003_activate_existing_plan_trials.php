<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscriptions') || ! Schema::hasTable('plans')) {
            return;
        }

        $subscriptions = DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', 'incomplete')
            ->where('plans.trial_days', '>', 0)
            ->select([
                'subscriptions.id',
                'subscriptions.started_at',
                'subscriptions.created_at',
                'plans.trial_days',
            ])
            ->get();

        foreach ($subscriptions as $subscription) {
            $startedAt = CarbonImmutable::parse($subscription->started_at ?: $subscription->created_at);
            $endsAt = $startedAt->addDays((int) $subscription->trial_days);
            if (! $endsAt->isFuture()) {
                continue;
            }

            DB::table('subscriptions')->where('id', $subscription->id)->update([
                'provider' => 'lookdo',
                'status' => 'trialing',
                'current_period_start' => $startedAt,
                'current_period_end' => $endsAt,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Existing trial access is intentionally not revoked on rollback.
    }
};

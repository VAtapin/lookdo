<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('subscriptions')) {
            return;
        }

        if (! Schema::hasColumn('tenants', 'manual_access_until')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->timestamp('manual_access_until')->nullable()->after('last_activity_at');
            });
        }

        $tenantIds = DB::table('subscriptions')
            ->where(function ($query): void {
                $query->where('complimentary', true)
                    ->orWhere('status', 'complimentary')
                    ->orWhere('provider', 'manual');
            })
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            DB::transaction(function () use ($tenantId): void {
                $subscriptions = DB::table('subscriptions')
                    ->where('tenant_id', $tenantId)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->get();
                $manual = $subscriptions->filter(fn (object $subscription): bool => (bool) $subscription->complimentary || $subscription->status === 'complimentary' || $subscription->provider === 'manual'
                );
                $manualUntil = $manual->pluck('current_period_end')->filter()->max();

                if ($manualUntil) {
                    $existing = DB::table('tenants')->where('id', $tenantId)->value('manual_access_until');
                    $until = $existing && Carbon::parse($existing)->greaterThan(Carbon::parse($manualUntil)) ? $existing : $manualUntil;
                    DB::table('tenants')->where('id', $tenantId)->update(['manual_access_until' => $until]);
                }

                $billing = $subscriptions->reject(fn (object $subscription): bool => $manual->contains('id', $subscription->id));
                if ($billing->isNotEmpty()) {
                    DB::table('subscriptions')->whereIn('id', $manual->pluck('id'))->update([
                        'status' => 'superseded',
                        'updated_at' => now(),
                    ]);

                    return;
                }

                $keep = $manual->first();
                if (! $keep) {
                    return;
                }

                DB::table('subscriptions')->where('id', $keep->id)->update([
                    'provider' => 'stripe',
                    'status' => 'incomplete',
                    'complimentary' => false,
                    'current_period_start' => null,
                    'current_period_end' => null,
                    'updated_at' => now(),
                ]);
                DB::table('subscriptions')
                    ->whereIn('id', $manual->pluck('id')->reject(fn (int $id): bool => $id === $keep->id))
                    ->update(['status' => 'superseded', 'updated_at' => now()]);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscriptions')) {
            DB::table('subscriptions')
                ->where('status', 'superseded')
                ->where('provider', 'manual')
                ->update(['status' => 'complimentary', 'updated_at' => now()]);
        }

        if (Schema::hasColumn('tenants', 'manual_access_until')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('manual_access_until'));
        }
    }
};

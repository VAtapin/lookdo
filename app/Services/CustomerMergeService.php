<?php

namespace App\Services;

use App\Models\TenantCustomer;
use Illuminate\Support\Facades\DB;

class CustomerMergeService
{
    public function merge(TenantCustomer $target, TenantCustomer $source): TenantCustomer
    {
        abort_if($target->tenant_id !== $source->tenant_id || $target->is($source), 422);

        return DB::transaction(function () use ($target, $source): TenantCustomer {
            $target = TenantCustomer::query()->lockForUpdate()->findOrFail($target->id);
            $source = TenantCustomer::query()->lockForUpdate()->findOrFail($source->id);
            abort_if($target->tenant_id !== $source->tenant_id || $target->is($source), 422);

            foreach (['tenant_requests', 'tenant_appointments', 'tenant_messages', 'tenant_client_tokens', 'tenant_push_subscriptions', 'tenant_reminders'] as $table) {
                DB::table($table)
                    ->where('tenant_id', $target->tenant_id)
                    ->where('customer_id', $source->id)
                    ->update(['customer_id' => $target->id]);
            }

            $segmentIds = DB::table('tenant_customer_segment')
                ->where('tenant_customer_id', $source->id)
                ->pluck('tenant_segment_id');
            foreach ($segmentIds as $segmentId) {
                DB::table('tenant_customer_segment')->updateOrInsert(
                    ['tenant_customer_id' => $target->id, 'tenant_segment_id' => $segmentId],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
            DB::table('tenant_customer_segment')->where('tenant_customer_id', $source->id)->delete();

            $target->update([
                'name' => $target->name ?: $source->name,
                'phone' => $target->phone ?: $source->phone,
                'phone_normalized' => $target->phone_normalized ?: $source->phone_normalized,
                'email' => $target->email ?: $source->email,
                'locale' => $target->locale ?: $source->locale,
                'preferred_channel' => $target->preferred_channel ?: $source->preferred_channel,
                'possible_duplicate_of_id' => null,
                'phone_verified_at' => $target->phone_verified_at ?: $source->phone_verified_at,
                'email_verified_at' => $target->email_verified_at ?: $source->email_verified_at,
                'last_activity_at' => collect([$target->last_activity_at, $source->last_activity_at])->filter()->sortDesc()->first(),
            ]);
            TenantCustomer::query()
                ->where('tenant_id', $target->tenant_id)
                ->where('possible_duplicate_of_id', $source->id)
                ->update(['possible_duplicate_of_id' => $target->id]);
            $source->delete();

            return $target->fresh(['possibleDuplicate', 'segments']);
        });
    }
}

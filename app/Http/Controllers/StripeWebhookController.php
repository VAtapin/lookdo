<?php

namespace App\Http\Controllers;

use App\Models\ImageCreditPurchase;
use App\Models\StripeWebhookEvent;
use App\Models\Subscription;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeService $stripe): JsonResponse
    {
        $payload = $request->getContent();
        abort_unless($stripe->validSignature($payload, (string) $request->header('Stripe-Signature')), 400);
        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $object = $event['data']['object'] ?? [];
        $type = $event['type'] ?? '';
        $record = StripeWebhookEvent::firstOrCreate(['event_id' => (string) ($event['id'] ?? hash('sha256', $payload))], ['type' => $type]);
        if (! $record->wasRecentlyCreated && $record->processed_at) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            if (($object['metadata']['lookdo_type'] ?? null) === 'image_credit') {
                DB::transaction(function () use ($object): void {
                    $purchase = ImageCreditPurchase::lockForUpdate()->find($object['metadata']['purchase_id'] ?? null);
                    if (! $purchase || $purchase->fulfilled_at) {
                        return;
                    }
                    $purchase->tenant->profile()->firstOrCreate()->increment('image_generation_credits', $purchase->quantity);
                    $purchase->update([
                        'status' => 'paid',
                        'stripe_session_id' => $object['id'] ?? $purchase->stripe_session_id,
                        'stripe_payment_intent_id' => $object['payment_intent'] ?? null,
                        'fulfilled_at' => now(),
                    ]);
                });
            } else {
                $subscription = Subscription::find($object['metadata']['subscription_id'] ?? null);
                if ($subscription) {
                    $subscription->update(['status' => 'active', 'provider' => 'stripe', 'provider_customer_id' => $object['customer'] ?? null, 'provider_subscription_id' => $object['subscription'] ?? null, 'started_at' => $subscription->started_at ?? now(), 'current_period_start' => now()]);
                }
            }
        }
        if ($type === 'invoice.paid') {
            $subscription = Subscription::where('provider_subscription_id', $object['subscription'] ?? null)->first();
            if ($subscription) {
                $periodEnd = data_get($object, 'lines.data.0.period.end');
                $subscription->update(['status' => 'active', 'current_period_end' => $periodEnd ? now()->setTimestamp($periodEnd) : $subscription->current_period_end]);
                $subscription->payments()->updateOrCreate(['provider_payment_id' => $object['id'] ?? null], ['amount' => ((int) ($object['amount_paid'] ?? 0)) / 100, 'currency' => strtoupper($object['currency'] ?? 'EUR'), 'status' => 'paid', 'paid_at' => now(), 'provider_payload' => $object]);
            }
        }
        if ($type === 'invoice.payment_failed') {
            Subscription::where('provider_subscription_id', $object['subscription'] ?? null)->update(['status' => 'past_due']);
        }
        if ($type === 'customer.subscription.deleted') {
            Subscription::where('provider_subscription_id', $object['id'] ?? null)->update(['status' => 'canceled']);
        }
        $record->update(['processed_at' => now()]);

        return response()->json(['received' => true]);
    }
}

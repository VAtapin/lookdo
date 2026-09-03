<?php

namespace App\Http\Controllers;

use App\Models\ImageCreditPurchase;
use App\Models\StripeWebhookEvent;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
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
        if (in_array($type, ['invoice.created', 'invoice.finalized'], true)) {
            $subscription = $this->subscriptionForInvoice($object);
            if ($subscription) {
                $this->syncInvoice($subscription, $object);
            }
        }
        if ($type === 'invoice.paid') {
            $subscription = $this->subscriptionForInvoice($object);
            if ($subscription) {
                $periodEnd = data_get($object, 'lines.data.0.period.end');
                $subscription->update(['status' => 'active', 'current_period_end' => $periodEnd ? now()->setTimestamp($periodEnd) : $subscription->current_period_end]);
                $invoice = $this->syncInvoice($subscription, $object, 'paid');
                $subscription->payments()->updateOrCreate(['provider_payment_id' => $object['id'] ?? null], ['subscription_invoice_id' => $invoice->id, 'amount' => ((int) ($object['amount_paid'] ?? 0)) / 100, 'currency' => strtoupper($object['currency'] ?? 'EUR'), 'status' => 'paid', 'paid_at' => isset($object['status_transitions']['paid_at']) ? now()->setTimestamp((int) $object['status_transitions']['paid_at']) : now(), 'provider_payload' => $object]);
            }
        }
        if ($type === 'invoice.payment_failed') {
            $subscription = $this->subscriptionForInvoice($object);
            if ($subscription) {
                $subscription->update(['status' => 'past_due']);
                $this->syncInvoice($subscription, $object, 'overdue');
            }
        }
        if ($type === 'customer.subscription.deleted') {
            Subscription::where('provider_subscription_id', $object['id'] ?? null)->update(['status' => 'canceled']);
        }
        $record->update(['processed_at' => now()]);

        return response()->json(['received' => true]);
    }

    private function subscriptionForInvoice(array $invoice): ?Subscription
    {
        $providerSubscriptionId = is_array($invoice['subscription'] ?? null)
            ? ($invoice['subscription']['id'] ?? null)
            : ($invoice['subscription'] ?? data_get($invoice, 'parent.subscription_details.subscription'));

        if (filled($providerSubscriptionId)) {
            return Subscription::where('provider_subscription_id', $providerSubscriptionId)->first();
        }

        $localSubscriptionId = data_get($invoice, 'parent.subscription_details.metadata.subscription_id')
            ?? data_get($invoice, 'lines.data.0.metadata.subscription_id');

        return filled($localSubscriptionId) ? Subscription::find($localSubscriptionId) : null;
    }

    private function syncInvoice(Subscription $subscription, array $payload, ?string $forcedStatus = null): SubscriptionInvoice
    {
        $subscription->loadMissing(['tenant.profile', 'plan']);
        $profile = $subscription->tenant->profile;
        $total = ((int) ($payload['total'] ?? $payload['amount_due'] ?? $payload['amount_paid'] ?? 0)) / 100;
        $subtotal = ((int) ($payload['subtotal'] ?? $payload['subtotal_excluding_tax'] ?? round($total * 100))) / 100;
        $tax = max(0, round($total - $subtotal, 2));
        $taxRate = $subtotal > 0 ? round($tax / $subtotal * 100, 2) : 0;
        $stripeStatus = (string) ($payload['status'] ?? 'open');
        $status = $forcedStatus ?? match ($stripeStatus) {
            'paid' => 'paid',
            'void', 'uncollectible' => 'void',
            default => 'open',
        };
        $periodStart = data_get($payload, 'lines.data.0.period.start');
        $periodEnd = data_get($payload, 'lines.data.0.period.end');
        $createdAt = isset($payload['created']) ? now()->setTimestamp((int) $payload['created']) : now();
        $dueAt = isset($payload['due_date']) ? now()->setTimestamp((int) $payload['due_date']) : $createdAt->copy()->addDays(14);
        $paidAt = data_get($payload, 'status_transitions.paid_at');
        $recipientAddress = collect([
            $profile?->street,
            trim(implode(' ', array_filter([$profile?->postal_code, $profile?->city]))),
            $subscription->tenant->country,
        ])->filter()->implode("\n");

        return SubscriptionInvoice::updateOrCreate(
            ['provider_invoice_id' => (string) ($payload['id'] ?? '')],
            [
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'invoice_number' => filled($payload['number'] ?? null) ? (string) $payload['number'] : 'STRIPE-'.($payload['id'] ?? uniqid()),
                'status' => $status,
                'issue_date' => $createdAt->toDateString(),
                'due_date' => $dueAt->toDateString(),
                'period_start' => $periodStart ? now()->setTimestamp((int) $periodStart) : null,
                'period_end' => $periodEnd ? now()->setTimestamp((int) $periodEnd) : null,
                'description' => 'LOOKDO '.data_get($subscription->plan?->name, 'de', $subscription->plan?->code ?? 'Abonnement'),
                'amount_net' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $tax,
                'amount_total' => $total,
                'currency' => strtoupper((string) ($payload['currency'] ?? $subscription->currency ?? 'EUR')),
                'recipient_name' => $subscription->tenant->name,
                'recipient_address' => $recipientAddress,
                'hosted_invoice_url' => $payload['hosted_invoice_url'] ?? null,
                'invoice_pdf_url' => $payload['invoice_pdf'] ?? null,
                'paid_at' => $paidAt ? now()->setTimestamp((int) $paidAt) : null,
            ],
        );
    }
}

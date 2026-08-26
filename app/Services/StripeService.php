<?php

namespace App\Services;

use App\Models\ImageCreditPurchase;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class StripeService
{
    public function configured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    public function testConnection(): array
    {
        $response = $this->get('/v1/account');

        $secret = (string) config('services.stripe.secret');

        return ['id' => $response->json('id'), 'country' => $response->json('country'), 'livemode' => str_starts_with($secret, 'sk_live_') || str_starts_with($secret, 'rk_live_')];
    }

    public function checkout(Tenant $tenant, Plan $plan, string $email, string $cycle = 'monthly', string $currency = 'EUR'): string
    {
        $currency = strtoupper($currency);
        $amount = $plan->priceFor($currency, $cycle);
        if ($amount === null) {
            throw new RuntimeException('The selected currency is not configured for this plan.');
        }
        $price = $cycle === 'yearly' ? $plan->stripe_yearly_price_id : $plan->stripe_monthly_price_id;
        if (! $plan->stripe_product_id) {
            $plan = $this->syncPlan($plan);
            $price = $cycle === 'yearly' ? $plan->stripe_yearly_price_id : $plan->stripe_monthly_price_id;
        }
        $useSynchronizedPrice = $currency === strtoupper($plan->currency) && filled($price);
        $lineItem = $useSynchronizedPrice
            ? ['quantity' => 1, 'price' => $price]
            : ['quantity' => 1, 'price_data' => ['product' => $plan->stripe_product_id, 'currency' => strtolower($currency), 'unit_amount' => (int) round($amount * 100), 'recurring' => ['interval' => $cycle === 'yearly' ? 'year' : 'month']]];
        $subscription = $tenant->subscriptions()->latest()->firstOrFail();
        $response = $this->post('/v1/checkout/sessions', [
            'mode' => 'subscription', 'customer_email' => $email, 'client_reference_id' => (string) $tenant->id,
            'success_url' => rtrim(config('app.url'), '/').'/app/billing?checkout=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => rtrim(config('app.url'), '/').'/app/billing?checkout=cancelled',
            'line_items' => [$lineItem],
            'automatic_tax' => ['enabled' => $this->formBoolean(config('services.stripe.automatic_tax'))],
            'metadata' => ['tenant_id' => (string) $tenant->id, 'subscription_id' => (string) $subscription->id, 'currency' => $currency, 'billing_cycle' => $cycle],
            'subscription_data' => ['metadata' => ['tenant_id' => (string) $tenant->id, 'subscription_id' => (string) $subscription->id, 'currency' => $currency, 'billing_cycle' => $cycle]],
        ], 'lookdo-subscription-'.$subscription->id.'-'.$cycle.'-'.strtolower($currency));
        if (! $response->json('url')) {
            throw new RuntimeException('Stripe Checkout returned no URL.');
        }

        return (string) $response->json('url');
    }

    /** @return array{url:string,session_id:string} */
    public function imageCreditCheckout(Tenant $tenant, string $email, ImageCreditPurchase $purchase): array
    {
        $amount = (int) round((float) $purchase->total_amount * 100);
        if ($amount < 1) {
            throw new RuntimeException('The image credit price is not configured.');
        }
        $response = $this->post('/v1/checkout/sessions', [
            'mode' => 'payment',
            'customer_email' => $email,
            'client_reference_id' => (string) $tenant->id,
            'success_url' => rtrim(config('app.url'), '/').'/app/business?image-credit=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => rtrim(config('app.url'), '/').'/app/business?image-credit=cancelled',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($purchase->currency),
                    'unit_amount' => $amount,
                    'product_data' => [
                        'name' => 'LOOKDO — '.$purchase->quantity.' KI-Bild'.($purchase->quantity === 1 ? '' : 'er'),
                        'metadata' => ['lookdo_type' => 'image_credit'],
                    ],
                ],
            ]],
            'automatic_tax' => ['enabled' => $this->formBoolean(config('services.stripe.automatic_tax'))],
            'metadata' => [
                'lookdo_type' => 'image_credit',
                'tenant_id' => (string) $tenant->id,
                'purchase_id' => (string) $purchase->id,
                'quantity' => (string) $purchase->quantity,
            ],
            'payment_intent_data' => ['metadata' => [
                'lookdo_type' => 'image_credit',
                'tenant_id' => (string) $tenant->id,
                'purchase_id' => (string) $purchase->id,
            ]],
        ], 'lookdo-image-credit-'.$purchase->id);
        if (! $response->json('url') || ! $response->json('id')) {
            throw new RuntimeException('Stripe Checkout returned no URL.');
        }

        return ['url' => (string) $response->json('url'), 'session_id' => (string) $response->json('id')];
    }

    public function syncPlan(Plan $plan): Plan
    {
        try {
            $productData = ['name' => 'LOOKDO — '.$plan->localized('name'), 'active' => $plan->is_active ? 'true' : 'false'];
            $product = $plan->stripe_product_id
                ? $this->post('/v1/products/'.$plan->stripe_product_id, $productData)
                : $this->post('/v1/products', $productData + ['description' => $plan->localized('description'), 'metadata' => ['lookdo_plan_id' => (string) $plan->id]]);
            $plan->stripe_product_id = (string) $product->json('id');
            $currency = strtolower($plan->currency);

            foreach (['monthly' => ['amount' => $plan->price_monthly, 'interval' => 'month'], 'yearly' => ['amount' => $plan->price_yearly, 'interval' => 'year']] as $cycle => $data) {
                $priceField = 'stripe_'.$cycle.'_price_id';
                $amountField = 'stripe_'.$cycle.'_amount';
                $amount = $data['amount'] === null ? null : (int) round((float) $data['amount'] * 100);
                if ($amount === null) {
                    if ($plan->$priceField) {
                        $this->post('/v1/prices/'.$plan->$priceField, ['active' => 'false']);
                    }
                    $plan->$priceField = null;
                    $plan->$amountField = null;

                    continue;
                }
                $changed = ! $plan->$priceField || (int) $plan->$amountField !== $amount || strtolower((string) $plan->stripe_currency) !== $currency;
                if ($changed) {
                    $oldPrice = $plan->$priceField;
                    $price = $this->post('/v1/prices', [
                        'product' => $plan->stripe_product_id, 'currency' => $currency, 'unit_amount' => $amount,
                        'recurring' => ['interval' => $data['interval']],
                        'metadata' => ['lookdo_plan_id' => (string) $plan->id, 'billing_cycle' => $cycle],
                    ]);
                    $plan->$priceField = (string) $price->json('id');
                    $plan->$amountField = $amount;
                    if ($oldPrice) {
                        $this->post('/v1/prices/'.$oldPrice, ['active' => 'false']);
                    }
                }
            }
            if ($plan->stripe_monthly_price_id) {
                $this->post('/v1/products/'.$plan->stripe_product_id, ['default_price' => $plan->stripe_monthly_price_id]);
            }
            $plan->forceFill(['stripe_currency' => strtoupper($plan->currency), 'stripe_synced_at' => now(), 'stripe_sync_error' => null])->save();

            return $plan->refresh();
        } catch (Throwable $exception) {
            $plan->forceFill(['stripe_sync_error' => $exception->getMessage()])->save();
            throw $exception;
        }
    }

    public function syncAllPlans(): int
    {
        $count = 0;
        Plan::where('is_active', true)->orderBy('sort_order')->each(function (Plan $plan) use (&$count) {
            $this->syncPlan($plan);
            $count++;
        });

        return $count;
    }

    public function validSignature(string $payload, string $header): bool
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if (! $secret || ! preg_match('/(?:^|,)t=(\d+)/', $header, $timestamp) || abs(time() - (int) $timestamp[1]) > 300) {
            return false;
        }
        preg_match_all('/(?:^|,)v1=([a-f0-9]+)/', $header, $signatures);
        $expected = hash_hmac('sha256', $timestamp[1].'.'.$payload, $secret);

        return collect($signatures[1])->contains(fn (string $signature) => hash_equals($expected, $signature));
    }

    private function get(string $path): Response
    {
        $this->ensureConfigured();
        $response = Http::withToken(config('services.stripe.secret'))->get('https://api.stripe.com'.$path);
        $this->ensureSuccessful($response);

        return $response;
    }

    private function post(string $path, array $data, ?string $idempotencyKey = null): Response
    {
        $this->ensureConfigured();
        $request = Http::asForm()->withToken(config('services.stripe.secret'));
        if ($idempotencyKey) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }
        $response = $request->post('https://api.stripe.com'.$path, $data);
        $this->ensureSuccessful($response);

        return $response;
    }

    private function formBoolean(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    private function ensureConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('STRIPE_SECRET is not configured.');
        }
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('error.message') ?: 'Stripe API error.'));
        }
    }
}

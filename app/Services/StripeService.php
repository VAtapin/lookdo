<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StripeService
{
    public function configured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    public function checkout(Tenant $tenant, Plan $plan, string $email, string $cycle = 'monthly'): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('Stripe is not configured.');
        }
        $price = $cycle === 'yearly' ? $plan->stripe_yearly_price_id : $plan->stripe_monthly_price_id;
        if (! $price) {
            throw new RuntimeException('The selected plan is not synchronized with Stripe.');
        }
        $subscription = $tenant->subscriptions()->latest()->firstOrFail();
        $response = Http::asForm()->withToken(config('services.stripe.secret'))->withHeaders(['Idempotency-Key' => 'lookdo-subscription-'.$subscription->id.'-'.$cycle])->post('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'subscription', 'customer_email' => $email, 'client_reference_id' => (string) $tenant->id,
            'success_url' => rtrim(config('app.url'), '/').'/app/billing?checkout=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => rtrim(config('app.url'), '/').'/app/billing?checkout=cancelled',
            'line_items' => [['quantity' => 1, 'price' => $price]],
            'automatic_tax' => ['enabled' => (bool) config('services.stripe.automatic_tax')],
            'metadata' => ['tenant_id' => (string) $tenant->id, 'subscription_id' => (string) $subscription->id],
            'subscription_data' => ['metadata' => ['tenant_id' => (string) $tenant->id, 'subscription_id' => (string) $subscription->id]],
        ]);
        if ($response->failed() || ! $response->json('url')) {
            throw new RuntimeException((string) ($response->json('error.message') ?: 'Stripe Checkout failed.'));
        }

        return (string) $response->json('url');
    }

    public function syncPlan(Plan $plan): Plan
    {
        if (! $this->configured()) {
            throw new RuntimeException('STRIPE_SECRET is not configured.');
        }
        $product = $plan->stripe_product_id ? $this->post('/v1/products/'.$plan->stripe_product_id, ['name' => 'LOOKDO — '.$plan->localized('name'), 'active' => $plan->is_active ? 'true' : 'false']) : $this->post('/v1/products', ['name' => 'LOOKDO — '.$plan->localized('name'), 'description' => $plan->localized('description'), 'metadata' => ['lookdo_plan_id' => (string) $plan->id]]);
        $plan->stripe_product_id = (string) $product->json('id');
        foreach (['monthly' => ['amount' => $plan->price_monthly, 'interval' => 'month'], 'yearly' => ['amount' => $plan->price_yearly, 'interval' => 'year']] as $key => $data) {
            if ($data['amount'] === null) {
                continue;
            } $field = 'stripe_'.$key.'_price_id';
            if (! $plan->$field) {
                $price = $this->post('/v1/prices', ['product' => $plan->stripe_product_id, 'currency' => strtolower($plan->currency), 'unit_amount' => (int) round((float) $data['amount'] * 100), 'recurring' => ['interval' => $data['interval']], 'metadata' => ['lookdo_plan_id' => (string) $plan->id, 'billing_cycle' => $key]]);
                $plan->$field = (string) $price->json('id');
            }
        }
        $plan->save();

        return $plan->refresh();
    }

    public function validSignature(string $payload, string $header): bool
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if (! $secret || ! preg_match('/(?:^|,)t=(\d+)/', $header, $ts)) {
            return false;
        } if (abs(time() - (int) $ts[1]) > 300) {
            return false;
        } preg_match_all('/(?:^|,)v1=([a-f0-9]+)/', $header, $sigs);
        $expected = hash_hmac('sha256', $ts[1].'.'.$payload, $secret);
        foreach ($sigs[1] as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

return false;
    }

    private function post(string $path, array $data): Response
    {
        $r = Http::asForm()->withToken(config('services.stripe.secret'))->post('https://api.stripe.com'.$path, $data);
        if ($r->failed()) {
            throw new RuntimeException((string) ($r->json('error.message') ?: 'Stripe API error'));
        }

return $r;
    }
}

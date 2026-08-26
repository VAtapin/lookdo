<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\AuditService;
use App\Services\DomainService;
use App\Services\EntitlementService;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use App\Services\StripeService;
use App\Services\TenantImageGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class TenantController extends Controller
{
    private function authorizeTenant(Request $request, Tenant $tenant): void
    {
        abort_unless($request->user()->is_super_admin || $request->user()->tenants()->whereKey($tenant->id)->exists(), 403);
    }

    public function show(Request $request, Tenant $tenant, EntitlementService $entitlements, TenantImageGenerationService $imageGenerations): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $tenant->load(['profile', 'domains', 'currentSubscription.plan.entitlements', 'currentSubscription.payments', 'businessProfile.category', 'businessProfile.variation', 'businessProfile.template']);
        if ($tenant->profile?->social_image_path) {
            $tenant->profile->setAttribute('social_image_url', Storage::disk('public')->url($tenant->profile->social_image_path));
        }

        return response()->json(['tenant' => $tenant, 'entitlements' => $entitlements->all($tenant), 'image_generation' => $imageGenerations->status($tenant), 'platform_url' => 'https://'.$tenant->slug.'.'.config('tenancy.platform_domain')]);
    }

    public function updateProfile(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate(['name' => 'required|string|max:160', 'locale' => ['nullable', Rule::in(['de', 'en', 'ru', 'uk'])], 'contact_name' => 'nullable|string|max:120', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:50', 'street' => 'nullable|string|max:160', 'postal_code' => 'nullable|string|max:30', 'city' => 'nullable|string|max:100', 'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $before = $tenant->load('profile')->toArray();
        $tenant->update(['name' => $data['name'], 'locale' => $data['locale'] ?? $tenant->locale]);
        unset($data['name'], $data['locale']);
        $tenant->profile()->updateOrCreate([], $data);
        $audit->log('tenant.profile.updated', $tenant, $before, $tenant->fresh('profile')->toArray(), $tenant->id);

        return response()->json(['tenant' => $tenant->fresh('profile')]);
    }

    public function uploadSocialImage(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240']);
        $profile = $tenant->profile()->firstOrCreate();
        $before = $profile->only(['social_image_path', 'social_image_source']);
        $path = $data['image']->store('tenant-social/'.$tenant->id, 'public');
        $this->replaceSocialImage($profile->social_image_path, $path);
        $profile->update(['social_image_path' => $path, 'social_image_source' => 'upload']);
        $audit->log('tenant.social_image.uploaded', $profile, $before, ['social_image_path' => $path, 'social_image_source' => 'upload'], $tenant->id);

        return response()->json(['social_image_path' => $path, 'social_image_url' => Storage::disk('public')->url($path), 'social_image_source' => 'upload'], 201);
    }

    public function prepareSocialImagePrompt(Request $request, Tenant $tenant, OpenAiService $openAi, OpenAiBudgetService $budget, TenantImageGenerationService $imageGenerations): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $tenant->load(['profile', 'businessProfile.category', 'businessProfile.variation', 'businessProfile.template']);
        $profile = $tenant->profile()->firstOrCreate();
        $context = [
            'business_name' => $tenant->name,
            'business_description' => $tenant->business_description,
            'selected_category' => $tenant->businessProfile?->category?->localized('name'),
            'selected_variation' => $tenant->businessProfile?->variation?->localized('name'),
            'selected_template' => $tenant->businessProfile?->template?->localized('name'),
            'template_code' => $tenant->businessProfile?->template?->code,
            'city' => $profile->city,
            'language' => $tenant->locale,
        ];

        try {
            $budget->ensureAvailable($request->user()?->id);
            $result = $openAi->structured(
                'Create one precise English prompt for a commercial social-sharing image. Combine the selected category and the exact business description into one coherent real-world service. The category defines the industry context and the description defines the action. For example, automotive plus installing doors means installing or repairing automobile doors in an auto workshop, never building doors. Do not invent unrelated services. Request realistic premium landscape photography with a clear central subject and useful tools or materials. Require no text, letters, logos, UI, watermarks, prices, phone numbers or invented brand marks. Return JSON only.',
                json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'social_image_prompt',
                [
                    'type' => 'object',
                    'properties' => ['prompt' => ['type' => 'string']],
                    'required' => ['prompt'],
                    'additionalProperties' => false,
                ],
            );
            $payload = json_decode($result['text'], true, 512, JSON_THROW_ON_ERROR);
            $prompt = trim((string) ($payload['prompt'] ?? ''));
            if ($prompt === '') {
                throw new RuntimeException('OpenAI returned an empty image prompt.');
            }
            $budget->record('tenant_social_image_prompt', $result['model'], $result['input_tokens'], $result['output_tokens'], $request->user()?->id, null, $tenant->id);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'IMAGE_PROMPT_PREPARATION_FAILED'], 422);
        }

        return response()->json(['prompt' => $prompt, 'context' => $context, 'image_generation' => $imageGenerations->status($tenant)]);
    }

    public function generateSocialImage(Request $request, Tenant $tenant, OpenAiService $openAi, OpenAiBudgetService $budget, AuditService $audit, TenantImageGenerationService $imageGenerations): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate(['prompt' => 'required|string|min:40|max:4000']);
        $tenant->load(['profile', 'currentSubscription.plan.entitlements']);
        $profile = $tenant->profile()->firstOrCreate();
        $reservation = null;

        try {
            $budget->ensureAvailable($request->user()?->id);
            $reservation = $imageGenerations->reserve($tenant);
            $result = $openAi->image($data['prompt']);
        } catch (Throwable $exception) {
            if ($reservation) {
                $imageGenerations->release($tenant, $reservation);
            }
            if ($exception->getMessage() === 'IMAGE_CREDIT_REQUIRED') {
                return response()->json(['message' => 'IMAGE_CREDIT_REQUIRED', 'image_generation' => $imageGenerations->status($tenant)], 402);
            }
            report($exception);

            return response()->json(['message' => 'Das Bild konnte nicht erstellt werden: '.$exception->getMessage(), 'image_generation' => $imageGenerations->status($tenant)], 422);
        }

        $path = 'tenant-social/'.$tenant->id.'/social-'.Str::uuid().'.'.$result['format'];
        Storage::disk('public')->put($path, $result['contents']);
        $before = $profile->only(['social_image_path', 'social_image_source']);
        $this->replaceSocialImage($profile->social_image_path, $path);
        $profile->update(['social_image_path' => $path, 'social_image_source' => 'ai']);
        $budget->recordImage('tenant_social_image_generation', $result['model'], $result['quality'], $request->user()?->id, $tenant->id);
        $audit->log('tenant.social_image.generated', $profile, $before, ['social_image_path' => $path, 'social_image_source' => 'ai', 'model' => $result['model'], 'prompt' => $data['prompt'], 'usage' => $reservation['type']], $tenant->id);

        return response()->json(['social_image_path' => $path, 'social_image_url' => Storage::disk('public')->url($path), 'social_image_source' => 'ai', 'image_generation' => $imageGenerations->status($tenant)], 201);
    }

    public function buyImageCredits(Request $request, Tenant $tenant, StripeService $stripe, TenantImageGenerationService $imageGenerations): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:20']);
        $status = $imageGenerations->status($tenant);
        $quantity = (int) $data['quantity'];
        $purchase = $tenant->imageCreditPurchases()->create([
            'user_id' => $request->user()?->id,
            'quantity' => $quantity,
            'unit_amount' => $status['unit_price'],
            'total_amount' => $status['unit_price'] * $quantity,
            'currency' => $status['currency'],
            'status' => 'pending',
        ]);

        try {
            $checkout = $stripe->imageCreditCheckout($tenant, $request->user()->email, $purchase);
            $purchase->update(['stripe_session_id' => $checkout['session_id']]);
        } catch (RuntimeException $exception) {
            $purchase->update(['status' => 'failed']);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['checkout_url' => $checkout['url']]);
    }
    private function replaceSocialImage(?string $oldPath, string $newPath): void
    {
        if ($oldPath && $oldPath !== $newPath && str_starts_with($oldPath, 'tenant-social/')) {
            Storage::disk('public')->delete($oldPath);
        }
    }

    public function updateSlug(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate(['slug' => ['required', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', Rule::unique('tenants', 'slug')->ignore($tenant->id), Rule::notIn(config('tenancy.reserved_slugs'))]]);
        abort_if($tenant->domains()->where('type', 'custom')->where('status', 'active')->exists(), 422, 'Contact support to change a live slug.');
        $before = $tenant->slug;
        DB::transaction(function () use ($tenant, $data) {
            $tenant->update(['slug' => $data['slug']]);
            $domain = $tenant->domains()->where('type', 'platform')->first();
            $domain?->update(['domain' => $data['slug'].'.'.config('tenancy.platform_domain')]);
        });
        $audit->log('tenant.slug.updated', $tenant, ['slug' => $before], ['slug' => $data['slug']], $tenant->id);

        return response()->json(['tenant' => $tenant->fresh('domains')]);
    }

    public function addDomain(Request $request, Tenant $tenant, EntitlementService $entitlements, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        abort_unless(filter_var($entitlements->get($tenant, 'custom_domain', 0), FILTER_VALIDATE_BOOLEAN), 403, 'Your plan does not include a custom domain.');
        $data = $request->validate(['domain' => 'required|string|max:253']);
        $domain = strtolower(trim(preg_replace('#^https?://#', '', $data['domain']), "/ \t\n\r\0\x0B"));
        abort_if(str_contains($domain, '/'), 422, 'Enter a hostname without a path.');
        $request->merge(['domain' => $domain]);
        validator(['domain' => $domain], ['domain' => ['required', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', Rule::unique('tenant_domains', 'domain')]])->validate();
        $record = $tenant->domains()->create(['domain' => $domain, 'type' => 'custom', 'status' => 'pending', 'verification_token' => Str::random(40)]);
        $audit->log('domain.created', $record, null, $record->toArray(), $tenant->id);

        return response()->json(['domain' => $record, 'dns' => ['type' => 'A/AAAA or CNAME', 'target' => parse_url(config('app.url'), PHP_URL_HOST)]], 201);
    }

    public function verifyDomain(Request $request, Tenant $tenant, TenantDomain $domain, DomainService $service, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        abort_unless($domain->tenant_id === $tenant->id, 404);
        $before = $domain->toArray();
        $domain = $service->verify($domain);
        $audit->log('domain.verified', $domain, $before, $domain->toArray(), $tenant->id);

        return response()->json(['domain' => $domain]);
    }

    public function removeDomain(Request $request, Tenant $tenant, TenantDomain $domain, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        abort_unless($domain->tenant_id === $tenant->id && $domain->type === 'custom' && $domain->status !== 'active', 422);
        $before = $domain->toArray();
        $domain->delete();
        $audit->log('domain.deleted', null, $before, null, $tenant->id);

        return response()->json(['ok' => true]);
    }

    public function checkout(Request $request, Tenant $tenant, StripeService $stripe): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate(['plan_id' => 'required|exists:plans,id', 'cycle' => ['required', Rule::in(['monthly', 'yearly'])], 'currency' => ['nullable', Rule::in(['EUR', 'RUB', 'UAH'])]]);
        $plan = Plan::where('is_active', true)->findOrFail($data['plan_id']);
        $currency = $data['currency'] ?? match ($tenant->locale) {
            'ru' => 'RUB', 'uk' => 'UAH', default => 'EUR'
        };
        $amount = $plan->priceFor($currency, $data['cycle']);
        abort_if($amount === null, 422, 'The selected currency is not configured for this plan.');
        $subscription = $tenant->currentSubscription;
        $reuseIncomplete = $subscription
            && $subscription->status === 'incomplete'
            && $subscription->plan_id === $plan->id
            && $subscription->billing_cycle === $data['cycle']
            && $subscription->currency === $currency;
        if (! $reuseIncomplete) {
            $subscription = $tenant->subscriptions()->create(['plan_id' => $plan->id, 'provider' => 'stripe', 'status' => 'incomplete', 'billing_cycle' => $data['cycle'], 'currency' => $currency, 'unit_amount' => $amount, 'started_at' => now()]);
        }
        try {
            $url = $stripe->checkout($tenant, $plan, $request->user()->email, $data['cycle'], $currency);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['checkout_url' => $url]);
    }
}

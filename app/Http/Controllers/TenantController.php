<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\AuditService;
use App\Services\DomainService;
use App\Services\EntitlementService;
use App\Services\ImageStorageService;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use App\Services\StripeService;
use App\Services\TenantImageGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TenantController extends Controller
{
    private function authorizeTenant(Request $request, Tenant $tenant): void
    {
        abort_unless($request->user()->is_super_admin || $request->user()->tenants()->whereKey($tenant->id)->exists(), 403);
    }

    private function requireActiveSubscription(Tenant $tenant): void
    {
        abort_unless($tenant->hasActiveSubscription(), 402, 'SUBSCRIPTION_PAYMENT_REQUIRED');
    }

    public function show(Request $request, Tenant $tenant, EntitlementService $entitlements, TenantImageGenerationService $imageGenerations): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $tenant->load(['profile', 'domains', 'currentSubscription.plan.entitlements', 'currentSubscription.payments', 'businessProfile.category', 'businessProfile.variation', 'businessProfile.template']);
        if ($tenant->profile?->social_image_path) {
            $tenant->profile->setAttribute('social_image_url', Storage::disk('public')->url($tenant->profile->social_image_path));
        }
        if ($tenant->profile?->logo_path) {
            $tenant->profile->setAttribute('logo_url', $this->assetUrl($tenant->profile->logo_path));
        }
        $branding = (array) data_get($tenant->profile?->content, 'branding', []);
        if (filled($branding['hero_image_path'] ?? null)) {
            $tenant->profile->setAttribute('hero_image_url', $this->assetUrl($branding['hero_image_path']));
        }
        if (filled($branding['horizontal_logo_path'] ?? null)) {
            $tenant->profile->setAttribute('horizontal_logo_url', $this->assetUrl($branding['horizontal_logo_path']));
        }
        $tenant->profile?->setAttribute('enabled_locales', array_values((array) data_get($tenant->profile?->content, 'enabled_locales', [$tenant->locale])));
        $tenant->profile?->setAttribute('branding', $branding);

        $subscription = $tenant->currentSubscription;
        $manualAccessActive = $tenant->hasManualAccess();
        $trialActive = ! $manualAccessActive && (bool) $subscription?->isTrialActive();

        return response()->json([
            'tenant' => $tenant,
            'access' => [
                'active' => $tenant->hasActiveSubscription(),
                'state' => $manualAccessActive ? 'complimentary' : ($subscription?->accessState() ?? 'unpaid'),
                'paid' => (bool) $subscription?->isPaidAccess(),
                'complimentary' => $manualAccessActive || (bool) $subscription?->isComplimentaryAccess(),
                'expires_at' => $manualAccessActive ? $tenant->manual_access_until?->toIso8601String() : $subscription?->accessEndsAt()?->toIso8601String(),
                'days_remaining' => $manualAccessActive ? $tenant->manual_access_days_remaining : ($subscription?->access_days_remaining ?? 0),
                'trial' => $trialActive,
                'trial_ends_at' => $trialActive ? $subscription?->trialEndsAt()?->toIso8601String() : null,
                'trial_days_remaining' => $trialActive ? ($subscription?->trial_days_remaining ?? 0) : 0,
            ],
            'entitlements' => $entitlements->all($tenant),
            'image_generation' => $imageGenerations->status($tenant),
            'platform_url' => 'https://'.$tenant->slug.'.'.config('tenancy.platform_domain'),
        ]);
    }

    public function updateProfile(Request $request, Tenant $tenant, AuditService $audit, EntitlementService $entitlements): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate(['name' => 'required|string|max:160', 'country' => 'nullable|string|size:2', 'timezone' => 'nullable|timezone', 'locale' => ['nullable', Rule::in(['de', 'en', 'ru', 'uk'])], 'enabled_locales' => 'nullable|array|min:1|max:4', 'enabled_locales.*' => [Rule::in(['de', 'en', 'ru', 'uk'])], 'contact_name' => 'nullable|string|max:120', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:50', 'street' => 'nullable|string|max:160', 'postal_code' => 'nullable|string|max:30', 'city' => 'nullable|string|max:100', 'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'notification_preferences' => 'nullable|array', 'notification_preferences.push' => 'nullable|boolean', 'notification_preferences.sms' => 'nullable|boolean', 'notification_preferences.email' => 'nullable|boolean']);
        $before = $tenant->load('profile')->toArray();
        $locale = $data['locale'] ?? $tenant->locale;
        if (isset($data['enabled_locales']) && ! in_array($locale, $data['enabled_locales'], true)) {
            $data['enabled_locales'][] = $locale;
        }
        $tenant->update(['name' => $data['name'], 'locale' => $locale, 'country' => strtoupper($data['country'] ?? $tenant->country), 'timezone' => $data['timezone'] ?? $tenant->timezone]);
        unset($data['name'], $data['locale'], $data['country'], $data['timezone']);
        $profile = $tenant->profile()->firstOrNew();
        if (array_key_exists('enabled_locales', $data)) {
            $content = (array) ($data['content'] ?? $profile->content);
            $content['enabled_locales'] = array_values(array_unique($data['enabled_locales']));
            $data['content'] = $content;
            unset($data['enabled_locales']);
        }
        if (array_key_exists('notification_preferences', $data)) {
            if (! filter_var($entitlements->get($tenant, 'sms_enabled', false), FILTER_VALIDATE_BOOL)) {
                $data['notification_preferences']['sms'] = false;
            }
            $content = (array) ($data['content'] ?? $profile->content);
            $content['notifications'] = $data['notification_preferences'];
            $data['content'] = $content;
            unset($data['notification_preferences']);
        }
        $profile->fill($data)->save();
        $audit->log('tenant.profile.updated', $tenant, $before, $tenant->fresh('profile')->toArray(), $tenant->id);

        return response()->json(['tenant' => $tenant->fresh('profile')]);
    }

    public function updateBranding(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate([
            'business_description' => 'nullable|string|max:3000',
            'services' => 'nullable|string|max:3000',
            'customers' => 'nullable|string|max:2000',
            'style' => 'nullable|string|max:1000',
            'avoid' => 'nullable|string|max:1000',
            'tagline' => 'nullable|string|max:300',
            'description_translations' => 'nullable|array',
            'description_translations.*' => 'nullable|string|max:3000',
            'tagline_translations' => 'nullable|array',
            'tagline_translations.*' => 'nullable|string|max:300',
            'service_modes' => 'nullable|array|min:1|max:2',
            'service_modes.*' => ['required', Rule::in(['workshop', 'on_site'])],
            'vk_url' => 'nullable|url|max:500',
            'max_url' => 'nullable|url|max:500',
            'working_hours' => 'nullable|string|max:500',
            'confirmed' => 'nullable|boolean',
        ]);
        $profile = $tenant->profile()->firstOrCreate();
        $before = (array) data_get($profile->content, 'branding', []);
        $branding = array_replace($before, Arr::except($data, ['business_description', 'confirmed']));
        if (array_key_exists('confirmed', $data)) {
            $branding['confirmed_at'] = $data['confirmed'] ? now()->toIso8601String() : null;
        }
        $content = (array) $profile->content;
        $content['branding'] = $branding;
        $profile->update(['content' => $content]);
        if (array_key_exists('business_description', $data)) {
            $tenant->update(['business_description' => $data['business_description']]);
        }
        $audit->log('tenant.branding.updated', $profile, $before, $branding, $tenant->id);

        return response()->json(['branding' => $branding, 'tenant' => $tenant->fresh('profile')]);
    }

    public function uploadBrandingAsset(Request $request, Tenant $tenant, AuditService $audit, ImageStorageService $images): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate([
            'asset' => ['required', Rule::in(['logo', 'logo_horizontal', 'hero'])],
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:20480',
        ]);
        $profile = $tenant->profile()->firstOrCreate();
        $content = (array) $profile->content;
        $branding = (array) ($content['branding'] ?? []);
        $column = $data['asset'] === 'logo' ? 'logo_path' : null;
        $brandingKey = $data['asset'] === 'logo_horizontal' ? 'horizontal_logo_path' : 'hero_image_path';
        $old = $column ? $profile->{$column} : ($branding[$brandingKey] ?? null);
        $path = $images->storeUploaded(
            $data['image'],
            'tenant-app/'.$tenant->id.'/branding',
            'public',
            $data['asset'] === 'logo' ? 1024 : 2048,
            $data['asset'] === 'logo' ? 1024 : ($data['asset'] === 'logo_horizontal' ? 720 : 1600),
        );
        $this->replaceTenantAsset($old, $path, $tenant->id);
        if ($column) {
            $profile->logo_path = $path;
            $branding['logo_source'] = 'upload';
        } elseif ($data['asset'] === 'logo_horizontal') {
            $branding['horizontal_logo_path'] = $path;
            $branding['horizontal_logo_source'] = 'upload';
        } else {
            $branding['hero_image_path'] = $path;
            $branding['hero_source'] = 'upload';
        }
        $content['branding'] = $branding;
        $profile->content = $content;
        $profile->save();
        $audit->log('tenant.branding.asset_uploaded', $profile, ['path' => $old], ['asset' => $data['asset'], 'path' => $path], $tenant->id);

        return response()->json(['asset' => $data['asset'], 'path' => $path, 'url' => Storage::disk('public')->url($path), 'branding' => $branding], 201);
    }

    public function prepareBrandingPrompt(Request $request, Tenant $tenant, OpenAiService $openAi, OpenAiBudgetService $budget, TenantImageGenerationService $imageGenerations): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $this->requireActiveSubscription($tenant);
        $data = $request->validate(['asset' => ['required', Rule::in(['logo', 'hero'])]]);
        $tenant->load(['profile', 'businessProfile.category', 'businessProfile.variation', 'businessProfile.template']);
        $branding = (array) data_get($tenant->profile?->content, 'branding', []);
        $language = match ($tenant->locale) {
            'ru' => 'Russian', 'uk' => 'Ukrainian', 'de' => 'German', default => 'English'
        };
        $context = [
            'asset' => $data['asset'],
            'business_name' => $tenant->name,
            'business_description' => $tenant->business_description,
            'services' => $branding['services'] ?? null,
            'customers' => $branding['customers'] ?? null,
            'style' => $branding['style'] ?? null,
            'avoid' => $branding['avoid'] ?? null,
            'category' => $tenant->businessProfile?->category?->localized('name', $tenant->locale),
            'variation' => $tenant->businessProfile?->variation?->localized('name', $tenant->locale),
            'template' => $tenant->businessProfile?->template?->code,
        ];
        $budget->ensureAvailable($request->user()?->id);
        $instructions = $data['asset'] === 'logo'
            ? 'Write one editable image-generation prompt entirely in '.$language.' for a simple premium square business logo mark. It must remain legible as a tiny app icon, use no copyrighted marks, vehicle brand logos, photographs, mockups, text, letters or watermarks.'
            : 'Write one editable image-generation prompt entirely in '.$language.' for a premium vertical mobile-app hero photograph. Show the exact service in a realistic workplace, leave darker clean areas for interface text, and include no text, logos, vehicle brand marks, number plates, UI or watermarks.';
        $result = $openAi->structured($instructions.' Return JSON only.', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'tenant_branding_prompt', [
            'type' => 'object',
            'properties' => ['prompt' => ['type' => 'string']],
            'required' => ['prompt'],
            'additionalProperties' => false,
        ]);
        $payload = json_decode($result['text'], true, 512, JSON_THROW_ON_ERROR);
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        abort_if($prompt === '', 422, 'IMAGE_PROMPT_PREPARATION_FAILED');
        $budget->record('tenant_branding_prompt', $result['model'], $result['input_tokens'], $result['output_tokens'], $request->user()?->id, null, $tenant->id);

        return response()->json(['prompt' => $prompt, 'asset' => $data['asset'], 'image_generation' => $imageGenerations->status($tenant)]);
    }

    public function generateBrandingAsset(Request $request, Tenant $tenant, OpenAiService $openAi, OpenAiBudgetService $budget, AuditService $audit, TenantImageGenerationService $imageGenerations, ImageStorageService $images): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $this->requireActiveSubscription($tenant);
        $data = $request->validate(['asset' => ['required', Rule::in(['logo', 'hero'])], 'prompt' => 'required|string|min:40|max:4000']);
        $profile = $tenant->profile()->firstOrCreate();
        $reservation = null;
        try {
            $budget->ensureAvailable($request->user()?->id);
            $reservation = $imageGenerations->reserve($tenant);
            $result = $openAi->image($data['prompt'], 'medium', $data['asset'] === 'logo' ? '1024x1024' : '1024x1536');
        } catch (Throwable $exception) {
            if ($reservation) {
                $imageGenerations->release($tenant, $reservation);
            }
            if ($exception->getMessage() === 'IMAGE_CREDIT_REQUIRED') {
                return response()->json(['message' => 'IMAGE_CREDIT_REQUIRED', 'image_generation' => $imageGenerations->status($tenant)], 402);
            }
            report($exception);

            return response()->json(['message' => 'IMAGE_GENERATION_FAILED', 'image_generation' => $imageGenerations->status($tenant)], 422);
        }
        $content = (array) $profile->content;
        $branding = (array) ($content['branding'] ?? []);
        $old = $data['asset'] === 'logo' ? $profile->logo_path : ($branding['hero_image_path'] ?? null);
        $path = $images->storeBytes($result['contents'], 'tenant-app/'.$tenant->id.'/branding', $result['format'], 'public', $data['asset'] === 'logo' ? 1024 : 2048, $data['asset'] === 'logo' ? 1024 : 1600);
        $this->replaceTenantAsset($old, $path, $tenant->id);
        if ($data['asset'] === 'logo') {
            $profile->logo_path = $path;
            $branding['logo_source'] = 'ai';
        } else {
            $branding['hero_image_path'] = $path;
            $branding['hero_source'] = 'ai';
        }
        $content['branding'] = $branding;
        $profile->content = $content;
        $profile->save();
        $budget->recordImage('tenant_branding_'.$data['asset'], $result['model'], $result['quality'], $request->user()?->id, $tenant->id);
        $audit->log('tenant.branding.asset_generated', $profile, ['path' => $old], ['asset' => $data['asset'], 'path' => $path, 'usage' => $reservation['type']], $tenant->id);

        return response()->json(['asset' => $data['asset'], 'path' => $path, 'url' => Storage::disk('public')->url($path), 'branding' => $branding, 'image_generation' => $imageGenerations->status($tenant)], 201);
    }

    public function uploadSocialImage(Request $request, Tenant $tenant, AuditService $audit, ImageStorageService $images): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240']);
        $profile = $tenant->profile()->firstOrCreate();
        $before = $profile->only(['social_image_path', 'social_image_source']);
        $path = $images->storeUploaded($data['image'], 'tenant-social/'.$tenant->id, 'public', 1600, 1200);
        $this->replaceSocialImage($profile->social_image_path, $path);
        $profile->update(['social_image_path' => $path, 'social_image_source' => 'upload']);
        $audit->log('tenant.social_image.uploaded', $profile, $before, ['social_image_path' => $path, 'social_image_source' => 'upload'], $tenant->id);

        return response()->json(['social_image_path' => $path, 'social_image_url' => Storage::disk('public')->url($path), 'social_image_source' => 'upload'], 201);
    }

    public function prepareSocialImagePrompt(Request $request, Tenant $tenant, OpenAiService $openAi, OpenAiBudgetService $budget, TenantImageGenerationService $imageGenerations): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $this->requireActiveSubscription($tenant);
        $tenant->load(['profile', 'businessProfile.category', 'businessProfile.variation', 'businessProfile.template']);
        $profile = $tenant->profile()->firstOrCreate();
        $context = [
            'business_name' => $tenant->name,
            'business_description' => $tenant->business_description,
            'selected_category' => $tenant->businessProfile?->category?->localized('name', $tenant->locale),
            'selected_variation' => $tenant->businessProfile?->variation?->localized('name', $tenant->locale),
            'selected_template' => $tenant->businessProfile?->template?->localized('name', $tenant->locale),
            'template_code' => $tenant->businessProfile?->template?->code,
            'city' => $profile->city,
            'language' => $tenant->locale,
        ];

        $promptLanguage = match ($tenant->locale) {
            'ru' => 'Russian',
            'uk' => 'Ukrainian',
            'de' => 'German',
            default => 'English',
        };

        try {
            $budget->ensureAvailable($request->user()?->id);
            $result = $openAi->structured(
                'Create one precise prompt written entirely in '.$promptLanguage.' for a commercial social-sharing image. Combine the selected category and the exact business description into one coherent real-world service. The category defines the industry context and the description defines the action. For example, automotive plus installing doors means installing or repairing automobile doors in an auto workshop, never building doors. Do not invent unrelated services. Request realistic premium landscape photography with a clear central subject and useful tools or materials. Require no text, letters, logos, UI, watermarks, prices, phone numbers or invented brand marks. Return JSON only.',
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

    public function generateSocialImage(Request $request, Tenant $tenant, OpenAiService $openAi, OpenAiBudgetService $budget, AuditService $audit, TenantImageGenerationService $imageGenerations, ImageStorageService $images): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $this->requireActiveSubscription($tenant);
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

        $path = $images->storeBytes($result['contents'], 'tenant-social/'.$tenant->id, $result['format'], 'public', 1600, 1200);
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
        $this->requireActiveSubscription($tenant);
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

    private function replaceTenantAsset(?string $oldPath, string $newPath, int $tenantId): void
    {
        if ($oldPath && $oldPath !== $newPath && str_starts_with($oldPath, 'tenant-app/'.$tenantId.'/branding/')) {
            Storage::disk('public')->delete($oldPath);
        }
    }

    private function assetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, '/') || str_starts_with($path, 'http') ? $path : Storage::disk('public')->url($path);
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

    public function addDomain(Request $request, Tenant $tenant, EntitlementService $entitlements, DomainService $service, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        abort_unless(filter_var($entitlements->get($tenant, 'custom_domain', 0), FILTER_VALIDATE_BOOLEAN), 403, 'Your plan does not include a custom domain.');
        $data = $request->validate(['domain' => 'required|string|max:253']);
        $domain = strtolower(trim(preg_replace('#^https?://#', '', $data['domain']), "/ \t\n\r\0\x0B"));
        abort_if(str_contains($domain, '/'), 422, 'Enter a hostname without a path.');
        $request->merge(['domain' => $domain]);
        validator(['domain' => $domain], ['domain' => ['required', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', Rule::unique('tenant_domains', 'domain')]])->validate();
        $record = $tenant->domains()->create(['domain' => $domain, 'type' => 'custom', 'status' => 'pending', 'provisioning_status' => 'pending', 'verification_token' => Str::random(40)]);
        try {
            $record = $service->provision($record);
        } catch (RuntimeException) {
            $record = $record->refresh();
        }
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

    public function removeDomain(Request $request, Tenant $tenant, TenantDomain $domain, DomainService $service, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        abort_unless($domain->tenant_id === $tenant->id && $domain->type === 'custom', 422);
        $before = $domain->toArray();
        $service->remove($domain);
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
        if ($subscription?->isTrialActive()) {
            $subscription->update([
                'plan_id' => $plan->id,
                'billing_cycle' => $data['cycle'],
                'currency' => $currency,
                'unit_amount' => $amount,
            ]);
        } elseif (! $reuseIncomplete) {
            $subscription = $tenant->subscriptions()->create(['plan_id' => $plan->id, 'provider' => 'stripe', 'status' => 'incomplete', 'billing_cycle' => $data['cycle'], 'currency' => $currency, 'unit_amount' => $amount, 'started_at' => now()]);
        }
        try {
            $url = $stripe->checkout($tenant, $plan, $request->user()->email, $data['cycle'], $currency);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['checkout_url' => $url]);
    }

    public function billingPortal(Request $request, Tenant $tenant, StripeService $stripe): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $subscription = $tenant->subscriptions()->whereNotNull('provider_customer_id')->latest()->first();
        abort_unless($subscription?->provider_customer_id, 422, 'STRIPE_CUSTOMER_NOT_AVAILABLE');

        try {
            $url = $stripe->billingPortal(
                $subscription->provider_customer_id,
                rtrim(config('app.url'), '/').'/app/billing'
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['url' => $url]);
    }

    public function exportData(Request $request, Tenant $tenant): StreamedResponse
    {
        $this->authorizeTenant($request, $tenant);
        $tenant->load([
            'profile', 'domains', 'businessProfile', 'subscriptions.plan', 'subscriptions.payments',
            'users', 'customers', 'appRequests.media', 'appRequests.values', 'appRequests.messages',
            'appointments', 'services', 'portfolioItems', 'reviews', 'socialDrafts', 'segments',
        ]);
        $filename = 'lookdo-'.$tenant->slug.'-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(function () use ($tenant): void {
            echo json_encode(['exported_at' => now()->toIso8601String(), 'tenant' => $tenant->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function destroyOwnAccount(Request $request, Tenant $tenant, StripeService $stripe, DomainService $domains): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $member = $tenant->users()->whereKey($request->user()->id)->firstOrFail();
        abort_unless($member->pivot->role === 'owner', 403, 'OWNER_REQUIRED');
        $data = $request->validate(['password' => 'required|string', 'confirmation' => 'required|string']);
        abort_unless(Hash::check($data['password'], $request->user()->password), 422, 'INVALID_PASSWORD');
        abort_unless($data['confirmation'] === $tenant->name, 422, 'CONFIRMATION_DOES_NOT_MATCH');

        foreach ($tenant->subscriptions()->whereNotNull('provider_subscription_id')->get() as $subscription) {
            $stripe->cancelSubscription($subscription->provider_subscription_id);
        }
        foreach ($tenant->domains()->where('type', 'custom')->get() as $domain) {
            $domains->remove($domain);
        }
        $user = $request->user();
        $tenantId = $tenant->id;
        DB::transaction(function () use ($tenant): void {
            $tenant->update(['primary_domain_id' => null]);
            $tenant->delete();
        });
        foreach (["tenant-app/$tenantId", "tenant-social/$tenantId", "tenant-branding/$tenantId", "tenant-services/$tenantId"] as $directory) {
            Storage::disk('public')->deleteDirectory($directory);
        }
        if (! $user->is_super_admin && ! $user->tenants()->exists()) {
            auth()->logout();
            $user->delete();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['deleted' => true]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\AiUsageRecord;
use App\Models\AuditLog;
use App\Models\BusinessCategory;
use App\Models\BusinessClassification;
use App\Models\BusinessPhrase;
use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\PlatformPage;
use App\Models\RequestTemplate;
use App\Models\Subscription;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\BusinessClassifier;
use App\Services\DomainService;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        return response()->json(['tenants' => Tenant::count(), 'active_tenants' => Tenant::where('status', 'active')->count(), 'trialing' => Subscription::where('status', 'trialing')->count(), 'paid' => Subscription::where('status', 'active')->where('complimentary', false)->count(), 'complimentary' => Subscription::where('complimentary', true)->count(), 'domains_attention' => TenantDomain::whereIn('status', ['failed', 'verifying', 'ssl_pending'])->count(), 'classifications_30d' => BusinessClassification::where('created_at', '>=', now()->subDays(30))->count(), 'ai_spend_month' => (float) AiUsageRecord::where('created_at', '>=', now()->startOfMonth())->sum('cost'), 'mrr' => (float) Subscription::join('plans', 'plans.id', '=', 'subscriptions.plan_id')->where('subscriptions.status', 'active')->where('subscriptions.complimentary', false)->sum(DB::raw('plans.price_monthly * (100 - subscriptions.discount_percent) / 100'))]);
    }

    public function tenants(Request $request): JsonResponse
    {
        $q = Tenant::with(['users:id,name,email', 'primaryDomain', 'currentSubscription.plan', 'businessProfile.category', 'businessProfile.variation'])->withCount('users');
        if ($s = $request->string('search')->trim()->toString()) {
            $q->where(fn ($x) => $x->where('name', 'like', "%$s%")->orWhere('slug', 'like', "%$s%"));
        } if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        $sort = $this->sortColumn($request, ['name', 'slug', 'status', 'created_at', 'last_activity_at']);

        return response()->json($q->orderBy($sort, $this->sortDirection($request))->paginate($this->perPage($request)));
    }

    public function createTenant(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:160', 'slug' => 'nullable|string|max:63|unique:tenants,slug',
            'owner_name' => 'required|string|max:120', 'owner_email' => 'required|email|max:255|unique:users,email',
            'owner_password' => ['required', Password::min(10)->letters()->numbers()],
            'country' => 'nullable|string|size:2', 'locale' => ['nullable', Rule::in(['de', 'en', 'ru', 'uk'])],
            'business_description' => 'nullable|string|max:1000', 'variation_id' => 'nullable|exists:business_variations,id',
            'plan_id' => 'required|exists:plans,id', 'complimentary' => 'nullable|boolean',
        ]);
        $tenant = DB::transaction(function () use ($data) {
            $slug = $this->uniqueTenantSlug($data['slug'] ?? $data['name']);
            $user = User::create(['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => $data['owner_password'], 'locale' => $data['locale'] ?? 'ru', 'is_active' => true]);
            $tenant = Tenant::create(['name' => $data['name'], 'slug' => $slug, 'status' => 'active', 'country' => strtoupper($data['country'] ?? 'DE'), 'locale' => $data['locale'] ?? 'ru', 'business_description' => $data['business_description'] ?? null]);
            $tenant->users()->attach($user, ['role' => 'owner']);
            $tenant->profile()->create(['contact_name' => $user->name, 'email' => $user->email]);
            $domain = $tenant->domains()->create(['domain' => $slug.'.'.config('tenancy.platform_domain'), 'type' => 'platform', 'is_primary' => true, 'status' => 'active', 'verified_at' => now(), 'ssl_status' => 'active', 'ssl_issued_at' => now()]);
            $tenant->update(['primary_domain_id' => $domain->id]);
            $tenant->subscriptions()->create(['plan_id' => $data['plan_id'], 'provider' => 'manual', 'status' => ($data['complimentary'] ?? false) ? 'complimentary' : 'active', 'complimentary' => $data['complimentary'] ?? false, 'started_at' => now()]);
            if (! empty($data['variation_id'])) {
                $variation = BusinessVariation::findOrFail($data['variation_id']);
                $template = RequestTemplate::where('code', $variation->template_code)->first();
                $tenant->businessProfile()->create(['category_id' => $variation->category_id, 'variation_id' => $variation->id, 'request_template_id' => $template?->id, 'original_description' => $data['business_description'] ?? null]);
            }

            return $tenant;
        });
        $audit->log('tenant.created_by_admin', $tenant, null, $tenant->toArray(), $tenant->id);

        return response()->json($tenant->load(['users', 'currentSubscription.plan', 'businessProfile.variation']), 201);
    }

    public function tenant(Tenant $tenant): JsonResponse
    {
        return response()->json($tenant->load(['users', 'profile', 'domains', 'subscriptions.plan', 'subscriptions.payments', 'businessProfile.category', 'businessProfile.variation', 'businessProfile.template']));
    }

    public function updateTenant(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['active', 'suspended', 'archived'])], 'plan_id' => 'nullable|exists:plans,id', 'complimentary' => 'nullable|boolean', 'discount_percent' => 'nullable|integer|min:0|max:100']);
        $before = $tenant->load('currentSubscription')->toArray();
        if (isset($data['status'])) {
            $tenant->update(['status' => $data['status']]);
        }if (isset($data['plan_id'])) {
            $sub = $tenant->currentSubscription;
            $payload = ['plan_id' => $data['plan_id'], 'complimentary' => $data['complimentary'] ?? false, 'discount_percent' => $data['discount_percent'] ?? 0, 'status' => ($data['complimentary'] ?? false) ? 'complimentary' : 'active', 'provider' => ($data['complimentary'] ?? false) ? 'manual' : ($sub?->provider ?? 'manual'), 'started_at' => $sub?->started_at ?? now()];
            $sub ? $sub->update($payload) : $tenant->subscriptions()->create($payload);
        } $audit->log('tenant.updated', $tenant, $before, $tenant->fresh('currentSubscription')->toArray(), $tenant->id);

        return response()->json($tenant->fresh(['currentSubscription.plan']));
    }

    public function setOverride(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['key' => 'required|string|max:100', 'value' => 'nullable|string|max:10000']);
        DB::table('tenant_entitlement_overrides')->updateOrInsert(['tenant_id' => $tenant->id, 'key' => $data['key']], ['value' => $data['value'], 'created_at' => now(), 'updated_at' => now()]);
        $audit->log('tenant.entitlement.updated', $tenant, null, $data, $tenant->id);

        return response()->json(['ok' => true]);
    }

    public function users(Request $request): JsonResponse
    {
        $q = User::with('tenants:id,name,slug');
        if ($s = $request->string('search')->trim()->toString()) {
            $q->where(fn ($x) => $x->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")->orWhereHas('tenants', fn ($tenant) => $tenant->where('name', 'like', "%$s%")));
        }
        if ($status = $request->string('status')->toString()) {
            $q->where('is_active', $status === 'active');
        }
        if ($role = $request->string('role')->toString()) {
            $role === 'super_admin' ? $q->where('is_super_admin', true) : $q->where('is_super_admin', false);
        }

        $sort = $this->sortColumn($request, ['name', 'email', 'is_active', 'created_at', 'last_login_at']);

        return response()->json($q->orderBy($sort, $this->sortDirection($request))->paginate($this->perPage($request)));
    }

    public function updateUser(Request $request, User $user, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['is_active' => 'sometimes|boolean', 'is_super_admin' => 'sometimes|boolean']);
        abort_if($data === [], 422, 'No changes supplied.');
        if (array_key_exists('is_super_admin', $data) && ! $data['is_super_admin'] && $user->is_super_admin) {
            abort_if($user->is($request->user()), 422, 'You cannot revoke your own super administrator access.');
            abort_if(User::where('is_super_admin', true)->count() <= 1, 422, 'At least one super administrator is required.');
        }
        $before = $user->toArray();
        $user->update($data);
        $audit->log('user.status.updated', $user, $before, $user->toArray());

        return response()->json($user);
    }

    public function sendPasswordReset(User $user, AuditService $audit): JsonResponse
    {
        $status = PasswordBroker::sendResetLink(['email' => $user->email]);
        $audit->log('user.password_reset.requested', $user);

        return response()->json(['status' => $status]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $query = Subscription::with(['tenant:id,name,slug', 'plan:id,code,name']);
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($provider = $request->string('provider')->toString()) {
            $query->where('provider', $provider);
        }
        if ($s = $request->string('search')->trim()->toString()) {
            $query->where(fn ($q) => $q->where('provider_customer_id', 'like', "%$s%")
                ->orWhere('provider_subscription_id', 'like', "%$s%")
                ->orWhereHas('tenant', fn ($tenant) => $tenant->where('name', 'like', "%$s%")->orWhere('slug', 'like', "%$s%")));
        }

        $sort = $this->sortColumn($request, ['status', 'provider', 'current_period_end', 'created_at']);

        return response()->json($query->orderBy($sort, $this->sortDirection($request))->paginate($this->perPage($request)));
    }

    public function plans(): JsonResponse
    {
        return response()->json(Plan::withCount('subscriptions')->with('entitlements')->orderBy('sort_order')->get());
    }

    public function planEntitlements(): JsonResponse
    {
        return response()->json([
            'groups' => config('plan_entitlements.groups', []),
            'definitions' => config('plan_entitlements.definitions', []),
        ]);
    }

    public function savePlan(Request $request, ?Plan $plan = null, ?AuditService $audit = null): JsonResponse
    {
        $locales = ['de', 'en', 'ru', 'uk'];
        $definitions = config('plan_entitlements.definitions', []);
        $rules = [
            'code' => ['required', 'alpha_dash', 'max:80', $plan ? Rule::unique('plans', 'code')->ignore($plan->id) : Rule::unique('plans', 'code')],
            'name' => ['required', 'array:'.implode(',', $locales)],
            'description' => ['nullable', 'array:'.implode(',', $locales)],
            'badge_text' => ['nullable', 'array:'.implode(',', $locales)],
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'nullable|numeric|min:0',
            'currency' => 'required|string|size:3',
            'trial_days' => 'integer|min:0|max:365',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer|min:0',
            'entitlements' => ['required', 'array:'.implode(',', array_keys($definitions))],
        ];
        foreach ($locales as $locale) {
            $rules["name.$locale"] = 'required|string|max:120';
            $rules["description.$locale"] = 'nullable|string|max:2000';
            $rules["badge_text.$locale"] = 'nullable|string|max:80';
        }
        foreach ($definitions as $key => $definition) {
            $rules["entitlements.$key"] = ($definition['type'] ?? 'boolean') === 'number'
                ? ['required', 'numeric', 'min:'.($definition['min'] ?? 0), 'max:'.($definition['max'] ?? PHP_INT_MAX)]
                : ['required', Rule::in([0, 1, '0', '1', false, true])];
        }

        $data = $request->validate($rules);
        $entitlements = $data['entitlements'];
        unset($data['entitlements']);
        $before = $plan?->toArray();
        $plan ? $plan->update($data) : $plan = Plan::create($data);
        foreach ($definitions as $key => $definition) {
            $value = $entitlements[$key];
            $plan->entitlements()->updateOrCreate(['key' => $key], ['value' => ($definition['type'] ?? 'boolean') === 'boolean' ? (int) filter_var($value, FILTER_VALIDATE_BOOL) : (string) $value]);
        }
        $plan->entitlements()->whereNotIn('key', array_keys($definitions))->delete();
        $audit?->log($before ? 'plan.updated' : 'plan.created', $plan, $before, $plan->fresh('entitlements')->toArray());

        return response()->json($plan->fresh('entitlements'), $before ? 200 : 201);
    }

    public function translatePlan(Request $request, OpenAiService $openAi, OpenAiBudgetService $budget, AuditService $audit): JsonResponse
    {
        $locales = ['de', 'en', 'ru', 'uk'];
        $data = $request->validate([
            'source_locale' => ['required', Rule::in($locales)],
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
            'badge_text' => 'nullable|string|max:80',
        ]);

        $localizedStrings = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array_fill_keys($locales, ['type' => 'string']),
            'required' => $locales,
        ];
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => $localizedStrings,
                'description' => $localizedStrings,
                'badge_text' => $localizedStrings,
            ],
            'required' => ['name', 'description', 'badge_text'],
        ];

        try {
            $budget->ensureAvailable($request->user()?->id);
            $result = $openAi->structured(
                'Translate SaaS tariff marketing content from the supplied source language into German, English, Russian, and Ukrainian. Preserve the exact commercial meaning. Do not invent or remove features, limits, prices, guarantees, or product claims. Keep plan names short. If badge_text is empty, return an empty badge_text in every language.',
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'lookdo_plan_translation',
                $schema,
            );
            $translations = json_decode($result['text'], true, flags: JSON_THROW_ON_ERROR);
            foreach (['name', 'description', 'badge_text'] as $field) {
                if (! is_array($translations[$field] ?? null)) {
                    throw new \RuntimeException('OpenAI returned an incomplete translation.');
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['translation' => $exception->getMessage()]);
        }

        $source = $data['source_locale'];
        $translations['name'][$source] = $data['name'];
        $translations['description'][$source] = $data['description'] ?? '';
        $translations['badge_text'][$source] = $data['badge_text'] ?? '';
        $budget->record('plan_translation', $result['model'], $result['input_tokens'], $result['output_tokens'], $request->user()?->id);
        $audit->log('plan.translation.generated', null, null, ['source_locale' => $source, 'model' => $result['model']]);

        return response()->json($translations);
    }

    public function syncPlan(Plan $plan, StripeService $stripe, AuditService $audit): JsonResponse
    {
        $before = $plan->toArray();
        $plan = $stripe->syncPlan($plan);
        $audit->log('plan.stripe.synced', $plan, $before, $plan->toArray());

        return response()->json($plan);
    }

    public function domains(Request $request): JsonResponse
    {
        $q = TenantDomain::with('tenant:id,name,slug');
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }
        if ($type = $request->string('type')->toString()) {
            $q->where('type', $type);
        }
        if ($s = $request->string('search')->trim()->toString()) {
            $q->where(fn ($query) => $query->where('domain', 'like', "%$s%")
                ->orWhereHas('tenant', fn ($tenant) => $tenant->where('name', 'like', "%$s%")));
        }

        $sort = $this->sortColumn($request, ['domain', 'type', 'status', 'last_checked_at', 'created_at']);

        return response()->json($q->orderBy($sort, $this->sortDirection($request))->paginate($this->perPage($request)));
    }

    public function verifyDomain(TenantDomain $domain, DomainService $service, AuditService $audit): JsonResponse
    {
        $before = $domain->toArray();
        $domain = $service->verify($domain);
        $audit->log('domain.admin.verified', $domain, $before, $domain->toArray(), $domain->tenant_id);

        return response()->json($domain);
    }

    public function activateDomain(TenantDomain $domain, AuditService $audit): JsonResponse
    {
        abort_unless(in_array($domain->status, ['ssl_pending', 'verifying'], true), 422);
        $before = $domain->toArray();
        DB::transaction(function () use ($domain) {
            $domain->tenant->domains()->update(['is_primary' => false]);
            $domain->update(['status' => 'active', 'ssl_status' => 'active', 'ssl_issued_at' => now(), 'is_primary' => true]);
            $domain->tenant->update(['primary_domain_id' => $domain->id]);
        });
        $audit->log('domain.activated', $domain, $before, $domain->toArray(), $domain->tenant_id);

        return response()->json($domain->fresh());
    }

    public function disableDomain(TenantDomain $domain, AuditService $audit): JsonResponse
    {
        abort_if($domain->type === 'platform', 422, 'A platform domain cannot be disabled.');
        $before = $domain->toArray();
        $domain->update(['status' => 'disabled', 'is_primary' => false, 'ssl_status' => 'disabled']);
        if ($domain->tenant->primary_domain_id === $domain->id) {
            $platform = $domain->tenant->domains()->where('type', 'platform')->first();
            $domain->tenant->update(['primary_domain_id' => $platform?->id]);
            $platform?->update(['is_primary' => true]);
        }
        $audit->log('domain.disabled', $domain, $before, $domain->toArray(), $domain->tenant_id);

        return response()->json($domain->fresh());
    }

    public function deleteDomain(TenantDomain $domain, AuditService $audit): JsonResponse
    {
        abort_if($domain->type === 'platform' || $domain->status === 'active', 422, 'Disable the custom domain before deleting it.');
        $before = $domain->toArray();
        $tenantId = $domain->tenant_id;
        $domain->delete();
        $audit->log('domain.deleted_by_admin', null, $before, null, $tenantId);

        return response()->json(['ok' => true]);
    }

    public function taxonomy(): JsonResponse
    {
        return response()->json(['categories' => BusinessCategory::with('variations')->orderBy('sort_order')->get(), 'templates' => RequestTemplate::orderByDesc('sort_order')->get()]);
    }

    public function saveCategory(Request $request, ?BusinessCategory $category = null, ?AuditService $audit = null): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'alpha_dash', 'max:100', $category ? Rule::unique('business_categories')->ignore($category->id) : Rule::unique('business_categories')], 'name' => 'required|array', 'enabled' => 'boolean', 'sort_order' => 'integer|min:0']);
        $before = $category?->toArray();
        $category ? $category->update($data) : $category = BusinessCategory::create($data);
        $audit?->log($before ? 'taxonomy.category.updated' : 'taxonomy.category.created', $category, $before, $category->toArray());

        return response()->json($category, $before ? 200 : 201);
    }

    public function saveVariation(Request $request, ?BusinessVariation $variation = null, ?AuditService $audit = null): JsonResponse
    {
        $data = $request->validate(['category_id' => 'required|exists:business_categories,id', 'code' => ['required', 'max:120', $variation ? Rule::unique('business_variations')->ignore($variation->id) : Rule::unique('business_variations')], 'name' => 'required|array', 'template_code' => 'nullable|string|max:160', 'enabled' => 'boolean', 'priority' => 'integer|min:0']);
        $before = $variation?->toArray();
        $variation ? $variation->update($data) : $variation = BusinessVariation::create($data);
        $audit?->log($before ? 'taxonomy.variation.updated' : 'taxonomy.variation.created', $variation, $before, $variation->toArray());

        return response()->json($variation, $before ? 200 : 201);
    }

    public function toggleTemplate(Request $request, RequestTemplate $template, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $before = $template->toArray();
        $template->update($data);
        $audit->log('template.toggled', $template, $before, $template->toArray());

        return response()->json($template);
    }

    public function saveTemplate(Request $request, ?RequestTemplate $template = null, ?AuditService $audit = null): JsonResponse
    {
        $data = $request->validate([
            'category_id' => 'nullable|exists:business_categories,id', 'variation_id' => 'nullable|exists:business_variations,id',
            'code' => ['required', 'string', 'max:160', $template ? Rule::unique('request_templates')->ignore($template->id) : Rule::unique('request_templates')],
            'parent_code' => 'nullable|string|max:160', 'name' => 'required|array', 'configuration' => 'required|array',
            'enabled' => 'boolean', 'version' => 'integer|min:1', 'sort_order' => 'integer|min:0',
        ]);
        $before = $template?->toArray();
        $template ? $template->update($data) : $template = RequestTemplate::create($data);
        $audit?->log($before ? 'template.updated' : 'template.created', $template, $before, $template->toArray());

        return response()->json($template, $before ? 200 : 201);
    }

    public function phrases(Request $request): JsonResponse
    {
        $q = BusinessPhrase::with(['category', 'variation']);
        if ($s = $request->string('search')->trim()->toString()) {
            $q->where('phrase', 'like', "%$s%");
        }if ($locale = $request->string('locale')->toString()) {
            $q->where('locale', $locale);
        }
        if ($status = $request->string('status')->toString()) {
            $q->where('enabled', $status === 'active');
        }
        if ($variation = $request->integer('variation_id')) {
            $q->where('variation_id', $variation);
        }

        $sort = $this->sortColumn($request, ['phrase', 'locale', 'weight', 'enabled', 'created_at']);

        return response()->json($q->orderBy($sort, $this->sortDirection($request))->paginate($this->perPage($request)));
    }

    public function savePhrase(Request $request, ?BusinessPhrase $phrase = null, ?AuditService $audit = null, ?BusinessClassifier $classifier = null): JsonResponse
    {
        $data = $request->validate(['category_id' => 'required|exists:business_categories,id', 'variation_id' => 'nullable|exists:business_variations,id', 'locale' => ['required', Rule::in(['de', 'en', 'ru', 'uk'])], 'phrase' => 'required|string|max:255', 'weight' => 'numeric|min:0.1|max:5', 'enabled' => 'boolean']);
        $data['normalized_phrase'] = $classifier->normalize($data['phrase']);
        $before = $phrase?->toArray();
        $phrase ? $phrase->update($data) : $phrase = BusinessPhrase::create($data);
        $audit?->log($before ? 'phrase.updated' : 'phrase.created', $phrase, $before, $phrase->toArray());

        return response()->json($phrase, $before ? 200 : 201);
    }

    public function classifications(Request $request): JsonResponse
    {
        $q = BusinessClassification::with(['category:id,code,name', 'variation:id,code,name', 'tenant:id,name,slug']);
        if ($s = $request->string('search')->trim()->toString()) {
            $q->where(fn ($query) => $query->where('original_text', 'like', "%$s%")
                ->orWhere('normalized_text', 'like', "%$s%")
                ->orWhereHas('tenant', fn ($tenant) => $tenant->where('name', 'like', "%$s%")));
        }
        if ($source = $request->string('source')->toString()) {
            $q->where('source', $source);
        }
        $sort = $this->sortColumn($request, ['confidence', 'source', 'confirmed_by_user_at', 'created_at']);

        return response()->json($q->orderBy($sort, $this->sortDirection($request))->paginate($this->perPage($request)));
    }

    public function settings(): JsonResponse
    {
        return response()->json(['settings' => SystemSetting::where('is_secret', false)->pluck('value', 'key'), 'pages' => PlatformPage::orderBy('key')->get()]);
    }

    public function saveSetting(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['key' => 'required|string|max:100', 'value' => 'nullable']);
        $setting = SystemSetting::firstOrNew(['key' => $data['key']]);
        $before = $setting->exists ? $setting->toArray() : null;
        $setting->value = $data['value'];
        $setting->is_secret = false;
        $setting->save();
        $audit->log('setting.updated', $setting, $before, $setting->toArray());

        return response()->json($setting);
    }

    public function savePage(Request $request, PlatformPage $page, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['title' => 'required|array', 'content' => 'nullable|array', 'is_published' => 'boolean']);
        $before = $page->toArray();
        $page->update($data);
        $audit->log('page.updated', $page, $before, $page->toArray());

        return response()->json($page);
    }

    public function uploadContentMedia(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp,gif,svg,mp4,webm,mov|max:102400']);
        $file = $data['file'];
        $path = $file->store('platform-content', 'public');
        $payload = ['url' => Storage::disk('public')->url($path), 'path' => $path, 'name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize()];
        $audit->log('content.media.uploaded', null, null, $payload);

        return response()->json($payload, 201);
    }

    public function audits(Request $request): JsonResponse
    {
        $q = AuditLog::query();
        if ($s = $request->string('search')->trim()->toString()) {
            $q->where(fn ($query) => $query->where('action', 'like', "%$s%")
                ->orWhere('subject_type', 'like', "%$s%")
                ->orWhere('ip_address', 'like', "%$s%"));
        }
        if ($action = $request->string('action')->toString()) {
            $q->where('action', 'like', $action.'%');
        }
        $sort = $this->sortColumn($request, ['action', 'actor_id', 'tenant_id', 'subject_type', 'created_at']);

        return response()->json($q->orderBy($sort, $this->sortDirection($request))->paginate($this->perPage($request)));
    }

    public function stripeStatus(StripeService $stripe): JsonResponse
    {
        return response()->json(['configured' => $stripe->configured(), 'webhook_configured' => filled(config('services.stripe.webhook_secret')), 'account' => $stripe->configured() ? $stripe->testConnection() : null, 'plans_pending' => Plan::whereNull('stripe_synced_at')->orWhereNotNull('stripe_sync_error')->count()]);
    }

    public function syncAllPlans(StripeService $stripe, AuditService $audit): JsonResponse
    {
        $count = $stripe->syncAllPlans();
        $audit->log('stripe.plans.synced', null, null, ['count' => $count]);

        return response()->json(['ok' => true, 'count' => $count]);
    }

    public function backups(BackupService $backups): JsonResponse
    {
        return response()->json(['path' => config('backup.path'), 'keep' => config('backup.keep'), 'backups' => $backups->list()]);
    }

    public function createBackup(BackupService $backups, AuditService $audit): JsonResponse
    {
        $manifest = $backups->create();
        $audit->log('backup.created', null, null, ['name' => $manifest['name']]);

        return response()->json($manifest, 201);
    }

    public function verifyBackup(string $name, BackupService $backups): JsonResponse
    {
        return response()->json($backups->verify($name));
    }

    public function deleteBackup(string $name, BackupService $backups, AuditService $audit): JsonResponse
    {
        $backups->delete($name);
        $audit->log('backup.deleted', null, ['name' => $name], null);

        return response()->json(['ok' => true]);
    }

    public function impersonate(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $owner = $tenant->users()->wherePivot('role', 'owner')->firstOrFail();
        $request->session()->put('impersonator_id', $request->user()->id);
        $audit->log('tenant.impersonation.started', $tenant, null, ['user_id' => $owner->id], $tenant->id);
        Auth::login($owner);
        $request->session()->regenerate();

        return response()->json(['ok' => true, 'tenant_id' => $tenant->id]);
    }

    public function stopImpersonation(Request $request): JsonResponse
    {
        $id = $request->session()->pull('impersonator_id');
        abort_unless($id, 422);
        Auth::loginUsingId($id);
        $request->session()->regenerate();

        return response()->json(['ok' => true]);
    }

    private function uniqueTenantSlug(string $value): string
    {
        $base = substr(trim(Str::slug(Str::ascii($value)) ?: 'business', '-'), 0, 50);
        if (in_array($base, config('tenancy.reserved_slugs'), true)) {
            $base .= '-business';
        }
        $slug = $base;
        $counter = 2;
        while (Tenant::where('slug', $slug)->exists() || in_array($slug, config('tenancy.reserved_slugs'), true)) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    private function perPage(Request $request): int
    {
        return max(10, min(100, $request->integer('per_page', 25)));
    }

    private function sortDirection(Request $request): string
    {
        return $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';
    }

    private function sortColumn(Request $request, array $allowed): string
    {
        $requested = $request->string('sort')->toString();

        return in_array($requested, $allowed, true) ? $requested : 'created_at';
    }
}

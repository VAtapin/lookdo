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
use App\Services\ImageStorageService;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiOrganizationUsageService;
use App\Services\OpenAiService;
use App\Services\StripeService;
use App\Services\TenantBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminController extends Controller
{
    public function dashboard(OpenAiOrganizationUsageService $organizationUsage): JsonResponse
    {
        $monthStart = now()->startOfMonth();
        $localAiQuery = AiUsageRecord::query()->where('created_at', '>=', $monthStart);
        $localAi = [
            'month_cost' => (float) (clone $localAiQuery)->sum('cost'),
            'requests' => (clone $localAiQuery)->count(),
            'input_tokens' => (int) (clone $localAiQuery)->sum('input_tokens'),
            'output_tokens' => (int) (clone $localAiQuery)->sum('output_tokens'),
            'images' => (clone $localAiQuery)->where('input_tokens', 0)->where('output_tokens', 0)->count(),
            'budget' => (float) config('services.openai.monthly_budget'),
            'by_tenant' => AiUsageRecord::query()
                ->leftJoin('tenants', 'tenants.id', '=', 'ai_usage_records.tenant_id')
                ->where('ai_usage_records.created_at', '>=', $monthStart)
                ->selectRaw("COALESCE(tenants.name, 'Plattform') as tenant_name, ai_usage_records.tenant_id, COUNT(*) as requests, SUM(ai_usage_records.input_tokens + ai_usage_records.output_tokens) as tokens, SUM(ai_usage_records.cost) as cost")
                ->groupBy('ai_usage_records.tenant_id', 'tenants.name')
                ->orderByDesc('cost')
                ->limit(8)
                ->get()
                ->map(fn ($row): array => [
                    'tenant_id' => $row->tenant_id,
                    'tenant_name' => $row->tenant_name,
                    'requests' => (int) $row->requests,
                    'tokens' => (int) $row->tokens,
                    'cost' => (float) $row->cost,
                ]),
        ];
        $openAiUsage = $organizationUsage->summary();

        $metrics = [
            'tenants' => Tenant::count(),
            'active_tenants' => Tenant::where('status', 'active')->count(),
            'trialing' => Subscription::where('status', 'trialing')->count(),
            'paid' => Subscription::where('status', 'active')->where('complimentary', false)->count(),
            'complimentary' => Tenant::where('manual_access_until', '>', now())->count(),
            'domains_attention' => TenantDomain::whereIn('status', ['failed', 'verifying', 'ssl_pending'])->count(),
            'classifications_30d' => BusinessClassification::where('created_at', '>=', now()->subDays(30))->count(),
            'ai_spend_month' => $localAi['month_cost'],
            'openai_spend_month' => $openAiUsage['status'] === 'connected' ? (float) $openAiUsage['month_cost'] : null,
            'mrr' => (float) Subscription::join('plans', 'plans.id', '=', 'subscriptions.plan_id')->where('subscriptions.status', 'active')->where('subscriptions.complimentary', false)->sum(DB::raw('plans.price_monthly * (100 - subscriptions.discount_percent) / 100')),
        ];

        $billingAttention = Subscription::whereIn('status', ['incomplete', 'past_due'])->count();
        $unpublishedPages = PlatformPage::where('is_published', false)->count();
        $legalMissing = collect(['legal_operator_name', 'legal_operator_address', 'legal_email', 'legal_phone'])
            ->filter(function (string $key): bool {
                $value = SystemSetting::read($key);

                return blank($value) || (is_string($value) && str_starts_with(trim($value), '['));
            })->count();
        $stripeAttention = Plan::whereNull('stripe_synced_at')->orWhereNotNull('stripe_sync_error')->count();

        $tasks = collect([
            ['key' => 'billing', 'title' => 'Zahlungen prüfen', 'description' => 'Unvollständige oder überfällige Abonnements', 'count' => $billingAttention, 'to' => '/control/subscriptions', 'severity' => 'danger'],
            ['key' => 'domains', 'title' => 'Domains prüfen', 'description' => 'DNS- oder SSL-Prüfung noch nicht abgeschlossen', 'count' => $metrics['domains_attention'], 'to' => '/control/tenants', 'severity' => 'warning'],
            ['key' => 'legal', 'title' => 'Rechtliche Angaben vervollständigen', 'description' => 'Fehlende Betreiber- oder Kontaktdaten', 'count' => $legalMissing, 'to' => '/control/settings', 'severity' => 'warning'],
            ['key' => 'content', 'title' => 'Inhalte veröffentlichen', 'description' => 'Noch nicht veröffentlichte Seiten', 'count' => $unpublishedPages, 'to' => '/control/content', 'severity' => 'info'],
            ['key' => 'stripe', 'title' => 'Stripe-Tarife synchronisieren', 'description' => 'Tarife ohne erfolgreiche Stripe-Synchronisierung', 'count' => $stripeAttention, 'to' => '/control/stripe', 'severity' => 'warning'],
        ])->filter(fn (array $task): bool => $task['count'] > 0)->values();

        $recentTenants = Tenant::latest()->limit(5)->get(['id', 'name', 'slug', 'status', 'created_at'])->map(fn (Tenant $tenant): array => [
            'id' => 'tenant-'.$tenant->id,
            'type' => 'tenant',
            'title' => $tenant->name,
            'description' => 'Neuer Kunde · '.$tenant->slug,
            'created_at' => $tenant->created_at,
            'to' => '/control/tenants',
            'tenant_id' => $tenant->id,
        ]);
        $recentAudits = AuditLog::latest()->limit(5)->get(['id', 'action', 'tenant_id', 'created_at'])->map(fn (AuditLog $log): array => [
            'id' => 'audit-'.$log->id,
            'type' => 'audit',
            'title' => $log->action,
            'description' => $log->tenant_id ? 'Kunde #'.$log->tenant_id : 'Plattform',
            'created_at' => $log->created_at,
            'to' => '/control/audit',
        ]);

        return response()->json($metrics + [
            'metrics' => [
                ['key' => 'tenants', 'value' => $metrics['tenants'], 'to' => '/control/tenants'],
                ['key' => 'active_tenants', 'value' => $metrics['active_tenants'], 'to' => '/control/tenants'],
                ['key' => 'trialing', 'value' => $metrics['trialing'], 'to' => '/control/subscriptions'],
                ['key' => 'paid', 'value' => $metrics['paid'], 'to' => '/control/subscriptions'],
                ['key' => 'domains_attention', 'value' => $metrics['domains_attention'], 'to' => '/control/tenants'],
                ['key' => 'ai_spend_month', 'value' => $metrics['ai_spend_month'], 'to' => '/control/settings/openai'],
                ['key' => 'openai_spend_month', 'value' => $metrics['openai_spend_month'], 'to' => '/control/settings/openai'],
                ['key' => 'mrr', 'value' => $metrics['mrr'], 'to' => '/control/subscriptions'],
            ],
            'ai_usage' => [
                'local' => $localAi,
                'openai' => $openAiUsage,
            ],
            'tasks' => $tasks,
            'recent' => $recentTenants->concat($recentAudits)->sortByDesc('created_at')->take(8)->values(),
        ]);
    }

    public function administrators(Request $request): JsonResponse
    {
        $query = User::query()->where('is_super_admin', true);
        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(fn ($item) => $item->where('name', 'like', "%$search%")->orWhere('email', 'like', "%$search%"));
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('is_active', $status === 'active');
        }
        $sort = $this->sortColumn($request, ['name', 'email', 'is_active', 'created_at', 'last_login_at']);

        return response()->json($query->orderBy($sort, $this->sortDirection($request))->paginate($this->perPage($request)));
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $query = Subscription::with(['tenant:id,name,slug', 'plan:id,code,name'])->where('status', '!=', 'superseded');
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
            'prices' => 'nullable|array:EUR,RUB,UAH',
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
        foreach (['EUR', 'RUB', 'UAH'] as $currency) {
            $rules["prices.$currency"] = 'nullable|array:monthly,yearly';
            $rules["prices.$currency.monthly"] = 'nullable|numeric|min:0';
            $rules["prices.$currency.yearly"] = 'nullable|numeric|min:0';
        }
        foreach ($definitions as $key => $definition) {
            $rules["entitlements.$key"] = ($definition['type'] ?? 'boolean') === 'number'
                ? ['required', 'numeric', 'min:'.($definition['min'] ?? 0), 'max:'.($definition['max'] ?? PHP_INT_MAX)]
                : ['required', Rule::in([0, 1, '0', '1', false, true])];
        }

        $data = $request->validate($rules);
        $entitlements = $data['entitlements'];
        unset($data['entitlements']);
        $data['prices'] = $data['prices'] ?? [strtoupper($data['currency']) => ['monthly' => $data['price_monthly'], 'yearly' => $data['price_yearly']]];
        if (isset($data['prices']['EUR']['monthly'])) {
            $data['currency'] = 'EUR';
            $data['price_monthly'] = $data['prices']['EUR']['monthly'];
            $data['price_yearly'] = $data['prices']['EUR']['yearly'] ?? null;
        }
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

    public function uploadPlanImage(Request $request, Plan $plan, AuditService $audit, ImageStorageService $images): JsonResponse
    {
        $request->validate(['image' => 'required|file|mimes:jpg,jpeg,png,webp|max:8192']);
        $before = $plan->toArray();
        $oldPath = $plan->image_path;
        $path = $images->storeUploaded($request->file('image'), 'plan-images/'.$plan->id, 'public', 1600, 1600);

        $plan->forceFill([
            'image_path' => $path,
            'stripe_synced_at' => null,
            'stripe_sync_error' => null,
        ])->save();
        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }
        $audit->log('plan.image.updated', $plan, $before, $plan->fresh()->toArray());

        return response()->json($plan->fresh(), 201);
    }

    public function deletePlanImage(Plan $plan, AuditService $audit): JsonResponse
    {
        $before = $plan->toArray();
        if ($plan->image_path) {
            Storage::disk('public')->delete($plan->image_path);
        }
        $plan->forceFill([
            'image_path' => null,
            'stripe_synced_at' => null,
            'stripe_sync_error' => null,
        ])->save();
        $audit->log('plan.image.deleted', $plan, $before, $plan->fresh()->toArray());

        return response()->json($plan->fresh());
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
        return response()->json([
            'settings' => SystemSetting::where('is_secret', false)->where('key', '!=', 'legal_dispute_statement')->pluck('value', 'key'),
            'pages' => PlatformPage::orderBy('key')->get(),
            'templates' => RequestTemplate::where('enabled', true)->orderByDesc('sort_order')->get(['id', 'code', 'name']),
            'sms' => [
                'providers' => [['value' => 'seven', 'label' => 'seven.io']],
                'api_key_configured' => filled(SystemSetting::readSecret('sms_seven_api_key')),
                'signing_key_configured' => filled(SystemSetting::readSecret('sms_seven_signing_key')),
                'webhook_url' => rtrim((string) config('app.url'), '/').'/api/webhooks/seven/sms',
            ],
            'openai' => [
                'admin_key_configured' => filled(SystemSetting::readSecret('openai_admin_key')),
                'project_id' => (string) SystemSetting::read('openai_project_id', ''),
                'usage_dashboard_url' => 'https://platform.openai.com/usage',
                'admin_keys_url' => 'https://platform.openai.com/settings/organization/admin-keys',
            ],
        ]);
    }

    public function saveSettings(Request $request, AuditService $audit): JsonResponse
    {
        $locales = ['de', 'en', 'ru', 'uk'];
        $data = $request->validate([
            'settings' => 'required|array',
            'settings.platform_name' => 'required|string|max:120',
            'settings.support_email' => 'nullable|email|max:255',
            'settings.default_locale' => ['required', Rule::in($locales)],
            'settings.default_request_template_code' => 'required|string|max:160|exists:request_templates,code',
            'settings.trial_days_default' => 'required|integer|min:0|max:365',
            'settings.upload_base_limit_mb' => 'required|integer|min:1|max:2048',
            'settings.social_share_image_url' => 'nullable|string|max:2048',
            'settings.social_share_images' => 'required|array:de,en,ru,uk',
            'settings.social_share_images.de' => 'required|string|max:2048',
            'settings.social_share_images.en' => 'required|string|max:2048',
            'settings.social_share_images.ru' => 'required|string|max:2048',
            'settings.social_share_images.uk' => 'required|string|max:2048',
            'settings.demo_video_source' => ['required', Rule::in(['none', 'upload', 'youtube'])],
            'settings.demo_video_url' => 'nullable|string|max:2048',
            'settings.registration_enabled' => 'required|boolean',
            'settings.maintenance' => 'required|boolean',
            'settings.enabled_locales' => 'required|array|min:1',
            'settings.enabled_locales.*' => Rule::in($locales),
            'settings.integrations' => 'required|array:stripe,openai,sms',
            'settings.integrations.stripe' => 'required|boolean',
            'settings.integrations.openai' => 'required|boolean',
            'settings.integrations.sms' => 'required|boolean',
            'settings.sms_provider' => ['required', Rule::in(['seven'])],
            'settings.sms_sender' => ['required', 'string', 'max:16', 'regex:/^(?:[A-Za-z0-9]{1,11}|[0-9]{1,16})$/'],
            'settings.sms_events' => 'required|array:request_received,master_replied,work_ready,agreement_reminder',
            'settings.sms_events.request_received' => 'required|boolean',
            'settings.sms_events.master_replied' => 'required|boolean',
            'settings.sms_events.work_ready' => 'required|boolean',
            'settings.sms_events.agreement_reminder' => 'required|boolean',
            'settings.sms_seven_api_key' => 'nullable|string|max:1024',
            'settings.sms_seven_signing_key' => 'nullable|string|max:1024',
            'settings.sms_clear_api_key' => 'nullable|boolean',
            'settings.sms_clear_signing_key' => 'nullable|boolean',
            'settings.openai_project_id' => ['nullable', 'string', 'max:120', 'regex:/^proj_[A-Za-z0-9_-]+$/'],
            'settings.openai_admin_key' => 'nullable|string|max:1024',
            'settings.openai_clear_admin_key' => 'nullable|boolean',
            'settings.legal_operator_name' => 'nullable|string|max:255',
            'settings.legal_operator_address' => 'nullable|string|max:2000',
            'settings.legal_representative' => 'nullable|string|max:255',
            'settings.legal_email' => 'nullable|email|max:255',
            'settings.legal_phone' => 'nullable|string|max:80',
            'settings.legal_register' => 'nullable|string|max:255',
            'settings.legal_vat_id' => 'nullable|string|max:120',
        ]);

        $allowed = [
            'platform_name', 'support_email', 'default_locale', 'default_request_template_code',
            'trial_days_default', 'upload_base_limit_mb', 'social_share_image_url', 'social_share_images', 'demo_video_source', 'demo_video_url', 'registration_enabled', 'maintenance',
            'enabled_locales', 'integrations', 'sms_provider', 'sms_sender', 'sms_events', 'legal_operator_name', 'legal_operator_address',
            'openai_project_id',
            'legal_representative', 'legal_email', 'legal_phone', 'legal_register', 'legal_vat_id',
        ];
        if (($data['settings']['demo_video_source'] ?? 'none') !== 'none' && blank($data['settings']['demo_video_url'] ?? null)) {
            throw ValidationException::withMessages(['settings.demo_video_url' => 'Bitte laden Sie ein Video hoch oder tragen Sie eine YouTube-URL ein.']);
        }
        if (($data['settings']['demo_video_source'] ?? 'none') === 'youtube' && ! preg_match('~^(?:https?://)?(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)[A-Za-z0-9_-]{6,}~i', (string) $data['settings']['demo_video_url'])) {
            throw ValidationException::withMessages(['settings.demo_video_url' => 'Bitte tragen Sie eine gültige YouTube-URL ein.']);
        }
        $smsEnabled = (bool) data_get($data, 'settings.integrations.sms', false);
        $newSmsApiKey = trim((string) data_get($data, 'settings.sms_seven_api_key', ''));
        $clearsSmsApiKey = (bool) data_get($data, 'settings.sms_clear_api_key', false);
        if ($smsEnabled && ($clearsSmsApiKey || ($newSmsApiKey === '' && blank(SystemSetting::readSecret('sms_seven_api_key'))))) {
            throw ValidationException::withMessages(['settings.sms_seven_api_key' => 'Zum Aktivieren des SMS-Versands ist ein seven.io API-Key erforderlich.']);
        }

        DB::transaction(function () use ($data, $allowed, $audit): void {
            foreach ($allowed as $key) {
                $setting = SystemSetting::firstOrNew(['key' => $key]);
                $before = $setting->exists ? $setting->toArray() : null;
                $setting->value = $data['settings'][$key] ?? null;
                $setting->is_secret = false;
                $setting->save();
                $audit->log('setting.updated', $setting, $before, $setting->toArray());
            }
            foreach ([
                'sms_seven_api_key' => 'sms_clear_api_key',
                'sms_seven_signing_key' => 'sms_clear_signing_key',
                'openai_admin_key' => 'openai_clear_admin_key',
            ] as $secretKey => $clearKey) {
                if ($data['settings'][$clearKey] ?? false) {
                    SystemSetting::writeSecret($secretKey, null);
                } elseif (filled($data['settings'][$secretKey] ?? null)) {
                    SystemSetting::writeSecret($secretKey, (string) $data['settings'][$secretKey]);
                }
            }
        });

        return $this->settings();
    }

    public function testOpenAiUsage(OpenAiOrganizationUsageService $usage): JsonResponse
    {
        $result = $usage->summary(true);
        if (($result['status'] ?? null) !== 'connected') {
            throw ValidationException::withMessages(['openai_admin_key' => $result['error'] ?? 'OpenAI Admin Key ist nicht konfiguriert.']);
        }

        return response()->json($result);
    }

    public function savePage(Request $request, PlatformPage $page, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['title' => 'required|array', 'content' => 'nullable|array', 'is_published' => 'boolean']);
        $before = $page->toArray();
        $page->update($data);
        $audit->log('page.updated', $page, $before, $page->toArray());

        return response()->json($page);
    }

    public function translatePage(Request $request, OpenAiService $openAi, OpenAiBudgetService $budget, AuditService $audit): JsonResponse
    {
        $locales = ['de', 'en', 'ru', 'uk'];
        $data = $request->validate([
            'source_locale' => ['required', Rule::in($locales)],
            'title' => 'required|string|max:255',
            'content' => 'nullable|string|max:200000',
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
            'properties' => ['title' => $localizedStrings, 'content' => $localizedStrings],
            'required' => ['title', 'content'],
        ];

        try {
            $budget->ensureAvailable($request->user()?->id);
            $result = $openAi->structured(
                'Translate this website page from the supplied source language into German, English, Russian, and Ukrainian. Preserve every HTML tag, link, URL, and template token such as {{operator_name}} exactly. Do not add legal promises, facts, clauses, or product functions. Return valid HTML in content.',
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'lookdo_page_translation',
                $schema,
            );
            $translations = json_decode($result['text'], true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($translations['title'] ?? null) || ! is_array($translations['content'] ?? null)) {
                throw new \RuntimeException('OpenAI returned an incomplete translation.');
            }
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['translation' => $exception->getMessage()]);
        }

        $source = $data['source_locale'];
        $translations['title'][$source] = $data['title'];
        $translations['content'][$source] = $data['content'] ?? '';
        $budget->record('page_translation', $result['model'], $result['input_tokens'], $result['output_tokens'], $request->user()?->id);
        $audit->log('page.translation.generated', null, null, ['source_locale' => $source, 'model' => $result['model']]);

        return response()->json($translations);
    }

    public function uploadContentMedia(Request $request, AuditService $audit, ImageStorageService $images): JsonResponse
    {
        $data = $request->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp,gif,svg,mp4,webm,mov|max:102400']);
        $file = $data['file'];
        $mime = (string) $file->getMimeType();
        $path = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            ? $images->storeUploaded($file, 'platform-content', 'public', 2400, 2400)
            : $file->store('platform-content', 'public');
        $payload = [
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime' => Storage::disk('public')->mimeType($path) ?: $mime,
            'size' => Storage::disk('public')->size($path),
        ];
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

    public function clearAudits(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['older', 'all'])],
            'days' => ['nullable', 'required_if:scope,older', 'integer', Rule::in([30, 90, 180, 365])],
            'confirmation' => ['nullable', 'required_if:scope,all', Rule::in(['PRÜFPROTOKOLL LÖSCHEN'])],
        ]);

        $query = AuditLog::query();
        $cutoff = null;
        if ($data['scope'] === 'older') {
            $cutoff = now()->subDays((int) $data['days']);
            $query->where('created_at', '<', $cutoff);
        }

        $deleted = $query->delete();
        $audit->log('audit.cleared', null, [
            'scope' => $data['scope'],
            'days' => $data['days'] ?? null,
            'cutoff' => $cutoff?->toIso8601String(),
            'deleted' => $deleted,
        ], ['retained' => AuditLog::count()]);

        return response()->json(['deleted' => $deleted, 'retained' => AuditLog::count()]);
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

    public function backups(Request $request, BackupService $backups, TenantBackupService $tenantBackups): JsonResponse
    {
        $tenants = Tenant::query()->orderBy('name')->get(['id', 'name', 'slug', 'status']);
        $selectedId = $request->filled('tenant_id') ? $request->integer('tenant_id') : null;
        $selected = $selectedId ? $tenants->firstWhere('id', $selectedId) : null;
        if ($selectedId && ! $selected) {
            throw ValidationException::withMessages(['tenant_id' => 'Der ausgewählte Kunde wurde nicht gefunden.']);
        }

        $items = ($selected ? collect([$selected]) : $tenants)
            ->flatMap(fn (Tenant $tenant) => collect($tenantBackups->list($tenant))->map(fn (array $backup): array => $backup + [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'tenant_slug' => $tenant->slug,
            ]));
        $search = mb_strtolower(trim($request->string('search')->toString()));
        if ($search !== '') {
            $items = $items->filter(fn (array $item): bool => str_contains(mb_strtolower(implode(' ', [
                $item['name'] ?? '',
                $item['tenant_name'] ?? '',
                $item['tenant_slug'] ?? '',
                $item['reason'] ?? '',
            ])), $search));
        }
        $sort = $this->sortColumn($request, ['created_at', 'name', 'tenant_name']);
        $items = $items->sortBy($sort, SORT_NATURAL | SORT_FLAG_CASE, $this->sortDirection($request) === 'desc')->values();
        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $pageItems = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'path' => $tenantBackups->path(),
            'keep' => config('backup.tenant_keep'),
            'selected_tenant_id' => $selected?->id,
            'tenants' => $tenants,
            'data' => $pageItems,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $total ? (($page - 1) * $perPage) + 1 : null,
            'to' => $total ? min($page * $perPage, $total) : null,
            'total' => $total,
            'platform_backups' => $backups->list(),
            'platform_path' => config('backup.path'),
            'platform_keep' => config('backup.keep'),
        ]);
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

    public function createTenantBackup(Request $request, Tenant $tenant, TenantBackupService $backups, AuditService $audit): JsonResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        $manifest = $backups->create($tenant);
        $audit->log('tenant_backup.created', $tenant, null, ['name' => $manifest['name']], $tenant->id);

        return response()->json($manifest, 201);
    }

    public function verifyTenantBackup(Tenant $tenant, string $name, TenantBackupService $backups): JsonResponse
    {
        return response()->json($backups->verify($tenant, $name));
    }

    public function restoreTenantBackup(Request $request, Tenant $tenant, string $name, TenantBackupService $backups, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['confirmation' => ['required', 'string']]);
        if (! hash_equals($tenant->slug, trim($data['confirmation']))) {
            throw ValidationException::withMessages(['confirmation' => 'Zur Bestätigung muss der Kundencode exakt eingegeben werden.']);
        }

        $result = $backups->restore($tenant, $name);
        $audit->log('tenant_backup.restored', $tenant, ['name' => $name], ['safety_backup' => $result['safety_backup']], $tenant->id);

        return response()->json($result);
    }

    public function deleteTenantBackup(Tenant $tenant, string $name, TenantBackupService $backups, AuditService $audit): JsonResponse
    {
        $backups->delete($tenant, $name);
        $audit->log('tenant_backup.deleted', $tenant, ['name' => $name], null, $tenant->id);

        return response()->json(['ok' => true]);
    }

    public function stopImpersonation(Request $request): JsonResponse
    {
        $id = $request->session()->pull('impersonator_id');
        abort_unless($id, 422);
        Auth::loginUsingId($id);
        $request->session()->regenerate();

        return response()->json(['ok' => true]);
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

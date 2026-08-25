<?php

namespace App\Http\Controllers;

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
use App\Services\BusinessClassifier;
use App\Services\DomainService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        return response()->json(['tenants' => Tenant::count(), 'active_tenants' => Tenant::where('status', 'active')->count(), 'trialing' => Subscription::where('status', 'trialing')->count(), 'paid' => Subscription::where('status', 'active')->where('complimentary', false)->count(), 'complimentary' => Subscription::where('complimentary', true)->count(), 'domains_attention' => TenantDomain::whereIn('status', ['failed', 'verifying', 'ssl_pending'])->count(), 'classifications_30d' => BusinessClassification::where('created_at', '>=', now()->subDays(30))->count(), 'mrr' => (float) Subscription::join('plans', 'plans.id', '=', 'subscriptions.plan_id')->where('subscriptions.status', 'active')->where('subscriptions.complimentary', false)->sum(DB::raw('plans.price_monthly * (100 - subscriptions.discount_percent) / 100'))]);
    }

    public function tenants(Request $request): JsonResponse
    {
        $q = Tenant::with(['users:id,name,email', 'primaryDomain', 'currentSubscription.plan', 'businessProfile.category', 'businessProfile.variation'])->withCount('users');
        if ($s = $request->string('search')->trim()->toString()) {
            $q->where(fn ($x) => $x->where('name', 'like', "%$s%")->orWhere('slug', 'like', "%$s%"));
        } if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

return response()->json($q->latest()->paginate(25));
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
            $q->where(fn ($x) => $x->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }

return response()->json($q->latest()->paginate(25));
    }

    public function updateUser(Request $request, User $user, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        $before = $user->toArray();
        $user->update($data);
        $audit->log('user.status.updated', $user, $before, $user->toArray());

        return response()->json($user);
    }

    public function plans(): JsonResponse
    {
        return response()->json(Plan::withCount('subscriptions')->with('entitlements')->orderBy('sort_order')->get());
    }

    public function savePlan(Request $request, ?Plan $plan = null, ?AuditService $audit = null): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'alpha_dash', 'max:80', $plan ? Rule::unique('plans', 'code')->ignore($plan->id) : Rule::unique('plans', 'code')], 'name' => 'required|array', 'description' => 'nullable|array', 'price_monthly' => 'required|numeric|min:0', 'price_yearly' => 'nullable|numeric|min:0', 'currency' => 'required|string|size:3', 'trial_days' => 'integer|min:0|max:365', 'is_active' => 'boolean', 'is_public' => 'boolean', 'sort_order' => 'integer|min:0', 'badge_text' => 'nullable|array', 'entitlements' => 'nullable|array']);
        $entitlements = $data['entitlements'] ?? null;
        unset($data['entitlements']);
        $before = $plan?->toArray();
        $plan ? $plan->update($data) : $plan = Plan::create($data);
        if (is_array($entitlements)) {
            foreach ($entitlements as $key => $value) {
                $plan->entitlements()->updateOrCreate(['key' => $key], ['value' => is_bool($value) ? (int) $value : (string) $value]);
            }$plan->entitlements()->whereNotIn('key', array_keys($entitlements))->delete();
        }$audit?->log($before ? 'plan.updated' : 'plan.created', $plan, $before, $plan->fresh('entitlements')->toArray());

        return response()->json($plan->fresh('entitlements'), $before ? 200 : 201);
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

return response()->json($q->latest()->paginate(30));
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

    public function phrases(Request $request): JsonResponse
    {
        $q = BusinessPhrase::with(['category', 'variation']);
        if ($s = $request->string('search')->trim()->toString()) {
            $q->where('phrase', 'like', "%$s%");
        }if ($locale = $request->string('locale')->toString()) {
            $q->where('locale', $locale);
        }

return response()->json($q->latest()->paginate(50));
    }

    public function savePhrase(Request $request, ?BusinessPhrase $phrase = null, ?AuditService $audit = null, ?BusinessClassifier $classifier = null): JsonResponse
    {
        $data = $request->validate(['category_id' => 'required|exists:business_categories,id', 'variation_id' => 'nullable|exists:business_variations,id', 'locale' => ['required', Rule::in(['de', 'en', 'ru'])], 'phrase' => 'required|string|max:255', 'weight' => 'numeric|min:0.1|max:5', 'enabled' => 'boolean']);
        $data['normalized_phrase'] = $classifier->normalize($data['phrase']);
        $before = $phrase?->toArray();
        $phrase ? $phrase->update($data) : $phrase = BusinessPhrase::create($data);
        $audit?->log($before ? 'phrase.updated' : 'phrase.created', $phrase, $before, $phrase->toArray());

        return response()->json($phrase, $before ? 200 : 201);
    }

    public function classifications(): JsonResponse
    {
        return response()->json(BusinessClassification::with(['category:id,code,name', 'variation:id,code,name', 'tenant:id,name,slug'])->latest()->paginate(50));
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

    public function audits(): JsonResponse
    {
        return response()->json(AuditLog::latest()->paginate(100));
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
        abort_unless($id,422);
        Auth::loginUsingId($id);
        $request->session()->regenerate();

        return response()->json(['ok' => true]);
    }
}

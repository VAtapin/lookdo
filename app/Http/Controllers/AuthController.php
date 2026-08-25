<?php

namespace App\Http\Controllers;

use App\Models\BusinessClassification;
use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\RequestTemplate;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BusinessClassifier;
use App\Services\StripeService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class AuthController extends Controller
{
    public function classify(Request $request, BusinessClassifier $classifier): JsonResponse
    {
        $data = $request->validate(['description' => 'required|string|min:3|max:1000', 'locale' => ['nullable', Rule::in(['de', 'en', 'ru'])]]);
        $result = $classifier->classify($data['description'], $data['locale'] ?? app()->getLocale());

        return response()->json($result);
    }

    public function register(Request $request, StripeService $stripe, AuditService $audit): JsonResponse
    {
        abort_unless((bool) SystemSetting::read('registration_enabled', true), 403, 'Registration is disabled.');
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()], 'business_name' => 'required|string|max:160', 'slug' => 'nullable|string|max:63', 'country' => 'required|string|size:2', 'locale' => ['required', Rule::in(['de', 'en', 'ru'])], 'business_description' => 'required|string|max:1000', 'classification_id' => 'required|exists:business_classifications,id', 'variation_id' => 'required|exists:business_variations,id', 'plan_id' => 'required|exists:plans,id', 'billing_cycle' => ['nullable', Rule::in(['monthly', 'yearly'])], 'accept_terms' => 'accepted', 'accept_privacy' => 'accepted']);
        $plan = Plan::where('is_active', true)->findOrFail($data['plan_id']);
        $variation = BusinessVariation::with('category')->findOrFail($data['variation_id']);
        $slug = $this->uniqueSlug($data['slug'] ?? $data['business_name']);
        [$user,$tenant,$subscription] = DB::transaction(function () use ($data, $plan, $variation, $slug) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'locale' => $data['locale'], 'is_active' => true]);
            $tenant = Tenant::create(['name' => $data['business_name'], 'slug' => $slug, 'country' => strtoupper($data['country']), 'locale' => $data['locale'], 'business_description' => $data['business_description'], 'status' => 'active']);
            $tenant->users()->attach($user, ['role' => 'owner']);
            $tenant->profile()->create(['contact_name' => $data['name'], 'email' => $data['email']]);
            $domain = $tenant->domains()->create(['domain' => $slug.'.'.config('tenancy.platform_domain'), 'type' => 'platform', 'is_primary' => true, 'status' => 'active', 'verified_at' => now(), 'ssl_status' => 'active', 'ssl_issued_at' => now()]);
            $tenant->update(['primary_domain_id' => $domain->id]);
            $template = RequestTemplate::where('code', $variation->template_code)->first();
            $tenant->businessProfile()->create(['category_id' => $variation->category_id, 'variation_id' => $variation->id, 'request_template_id' => $template?->id, 'original_description' => $data['business_description']]);
            BusinessClassification::whereKey($data['classification_id'])->update(['tenant_id' => $tenant->id, 'category_id' => $variation->category_id, 'variation_id' => $variation->id, 'confirmed_by_user_at' => now()]);
            $trial = $plan->trial_days > 0;
            $subscription = $tenant->subscriptions()->create(['plan_id' => $plan->id, 'provider' => $trial ? 'lookdo' : 'stripe', 'status' => $trial ? 'trialing' : 'incomplete', 'started_at' => now(), 'current_period_start' => now(), 'current_period_end' => $trial ? now()->addDays($plan->trial_days) : null]);

            return [$user, $tenant, $subscription];
        });
        Auth::login($user);
        $request->session()->regenerate();
        $audit->log('tenant.registered', $tenant, null, $tenant->toArray(), $tenant->id);
        $checkoutUrl = null;
        if ($subscription->status === 'incomplete') {
            try {
                $checkoutUrl = $stripe->checkout($tenant, $plan, $user->email, $data['billing_cycle'] ?? 'monthly');
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json(['user' => $user, 'tenant' => $tenant, 'checkout_url' => $checkoutUrl], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'remember' => 'boolean']);
        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password'], 'is_active' => true], $data['remember'] ?? false)) {
            return response()->json(['message' => __('auth.failed')], 422);
        } $request->session()->regenerate();
        $request->user()->update(['last_login_at' => now()]);

        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => __('passwords.sent')]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET ? response()->json(['message' => __($status)]) : response()->json(['message' => __($status)], 422);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['user' => $user, 'tenants' => $user?->tenants()->with(['profile', 'primaryDomain', 'currentSubscription.plan', 'businessProfile.category', 'businessProfile.variation'])->get() ?? [], 'impersonating' => (bool) $request->session()->get('impersonator_id')]);
    }

    private function uniqueSlug(string $value): string
    {
        $base = Str::slug(Str::ascii($value)) ?: 'business';
        $base = substr(trim($base, '-'), 0, 50);
        if (in_array($base, config('tenancy.reserved_slugs'), true)) {
            $base .= '-business';
        } $slug = $base;
        $i = 2;
        while (Tenant::where('slug', $slug)->exists() || in_array($slug,config('tenancy.reserved_slugs'),true)) {
            $slug = $base.'-'.$i++;
        }

return $slug;
    }
}

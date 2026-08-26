<?php

namespace App\Http\Controllers;

use App\Models\BusinessCategory;
use App\Models\Plan;
use App\Models\PlatformPage;
use App\Models\SystemSetting;
use App\Services\BusinessClassifier;
use App\Services\PlanFeaturePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformController extends Controller
{
    public function bootstrap(BusinessClassifier $classifier, PlanFeaturePresenter $features): JsonResponse
    {
        $plans = Plan::with('entitlements')->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->get()->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->localized('name'), 'description' => $p->localized('description'), 'price_monthly' => $p->price_monthly, 'price_yearly' => $p->price_yearly, 'currency' => $p->currency, 'prices' => $p->priceMatrix(), 'trial_days' => $p->trial_days, 'badge' => $p->localized('badge_text'), 'entitlements' => $p->entitlements->pluck('value', 'key'), 'features' => $features->forPlan($p, app()->getLocale())]);

        return response()->json(['locale' => app()->getLocale(), 'locales' => SystemSetting::read('enabled_locales', ['de', 'en', 'ru', 'uk']), 'registration_enabled' => SystemSetting::read('registration_enabled', true), 'default_template' => $classifier->defaultCandidate(), 'plans' => $plans, 'categories' => BusinessCategory::with(['variations' => fn ($q) => $q->where('enabled', true)->orderByDesc('priority')])->where('enabled', true)->orderBy('sort_order')->get()->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->localized('name'), 'variations' => $c->variations->map(fn ($v) => ['id' => $v->id, 'code' => $v->code, 'name' => $v->localized('name')])])]);
    }

    public function page(string $key): JsonResponse
    {
        $page = PlatformPage::where('key', $key)->where('is_published', true)->firstOrFail();

        return response()->json(['key' => $key, 'title' => $page->localized('title'), 'content' => $this->replaceLegalTokens($page->localized('content'))]);
    }

    public function tenantSite(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant, 404);
        app()->setLocale($tenant->locale);
        $tenant->load(['businessProfile.category', 'businessProfile.variation', 'businessProfile.template']);
        $configuration = $tenant->businessProfile?->template?->configuration ?? [];

        return response()->json([
            'name' => $tenant->name,
            'locale' => $tenant->locale,
            'description' => $tenant->business_description,
            'template' => [
                'name' => $tenant->businessProfile?->variation?->localized('name'),
                'category' => $tenant->businessProfile?->category?->localized('name'),
                'preview' => $configuration['preview'] ?? ['image' => '/brand/service-renovation.webp', 'primary_color' => '#ff6b00', 'secondary_color' => '#25282e'],
            ],
        ]);
    }

    private function replaceLegalTokens(string $content): string
    {
        $settings = config('legal_pages.operator_settings', []);
        $tokens = [];
        foreach ($settings as $key => $fallback) {
            $token = str_replace('legal_', '', $key);
            $value = (string) SystemSetting::read($key, $fallback);
            $tokens['{{'.$token.'}}'] = $token === 'operator_address' ? nl2br(e($value)) : e($value);
        }

        return strtr($content, $tokens);
    }
}

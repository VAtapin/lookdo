<?php

namespace App\Http\Controllers;

use App\Models\BusinessCategory;
use App\Models\Plan;
use App\Models\PlatformPage;
use App\Models\SystemSetting;
use App\Services\BusinessClassifier;
use Illuminate\Http\JsonResponse;

class PlatformController extends Controller
{
    public function bootstrap(BusinessClassifier $classifier): JsonResponse
    {
        $plans = Plan::with('entitlements')->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->get()->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->localized('name'), 'description' => $p->localized('description'), 'price_monthly' => $p->price_monthly, 'price_yearly' => $p->price_yearly, 'currency' => $p->currency, 'trial_days' => $p->trial_days, 'badge' => $p->localized('badge_text'), 'entitlements' => $p->entitlements->pluck('value', 'key')]);

        return response()->json(['locale' => app()->getLocale(), 'locales' => SystemSetting::read('enabled_locales', ['de', 'en', 'ru', 'uk']), 'registration_enabled' => SystemSetting::read('registration_enabled', true), 'default_template' => $classifier->defaultCandidate(), 'plans' => $plans, 'categories' => BusinessCategory::with(['variations' => fn ($q) => $q->where('enabled', true)->orderByDesc('priority')])->where('enabled', true)->orderBy('sort_order')->get()->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->localized('name'), 'variations' => $c->variations->map(fn ($v) => ['id' => $v->id, 'code' => $v->code, 'name' => $v->localized('name')])])]);
    }

    public function page(string $key): JsonResponse
    {
        $page = PlatformPage::where('key', $key)->where('is_published', true)->firstOrFail();

        return response()->json(['key' => $key, 'title' => $page->localized('title'), 'content' => $page->localized('content')]);
    }
}

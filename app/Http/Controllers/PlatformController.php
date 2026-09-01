<?php

namespace App\Http\Controllers;

use App\Models\BusinessCategory;
use App\Models\Plan;
use App\Models\PlatformPage;
use App\Models\SystemSetting;
use App\Services\BusinessClassifier;
use App\Services\PlanFeaturePresenter;
use App\Support\LegalContentSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PlatformController extends Controller
{
    public function bootstrap(BusinessClassifier $classifier, PlanFeaturePresenter $features): JsonResponse
    {
        $plans = Plan::with('entitlements')->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->get()->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->localized('name'), 'description' => $p->localized('description'), 'image_url' => $p->image_url, 'price_monthly' => $p->price_monthly, 'price_yearly' => $p->price_yearly, 'currency' => $p->currency, 'prices' => $p->priceMatrix(), 'trial_days' => $p->trial_days, 'badge' => $p->localized('badge_text'), 'entitlements' => $p->entitlements->pluck('value', 'key'), 'features' => $features->forPlan($p, app()->getLocale())]);

        return response()->json(['locale' => app()->getLocale(), 'locales' => SystemSetting::read('enabled_locales', ['de', 'en', 'ru', 'uk']), 'registration_enabled' => SystemSetting::read('registration_enabled', true), 'default_template' => $classifier->defaultCandidate(), 'demo_video' => ['source' => SystemSetting::read('demo_video_source', 'none'), 'url' => SystemSetting::read('demo_video_url', '')], 'plans' => $plans, 'categories' => BusinessCategory::with(['variations' => fn ($q) => $q->where('enabled', true)->orderByDesc('priority')])->where('enabled', true)->orderBy('sort_order')->get()->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->localized('name'), 'variations' => $c->variations->map(fn ($v) => ['id' => $v->id, 'code' => $v->code, 'name' => $v->localized('name')])])]);
    }

    public function manifest(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $tenant?->loadMissing('profile');
        $name = $tenant?->name ?: 'LOOKDO';
        $theme = $tenant?->profile?->primary_color ?: '#ff6a00';
        $background = $tenant?->profile?->secondary_color ?: '#111318';
        $iconVersion = ($tenant?->profile?->updated_at?->timestamp ?: 1).'-'.(@filemtime(__FILE__) ?: 1);

        return response()->json([
            'id' => '/', 'name' => $name, 'short_name' => Str::limit($name, 18, ''), 'description' => $tenant?->business_description ?: 'LOOKDO',
            'start_url' => '/', 'scope' => '/', 'display' => 'standalone', 'display_override' => ['window-controls-overlay', 'standalone'],
            'orientation' => 'portrait-primary', 'background_color' => $background, 'theme_color' => $theme,
            'icons' => [
                ['src' => "/tenant-icon/192.png?v=$iconVersion", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "/tenant-icon/512.png?v=$iconVersion", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "/tenant-icon/512.png?v=$iconVersion", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ])->header('Content-Type', 'application/manifest+json')->header('Cache-Control', 'no-cache, must-revalidate');
    }

    public function tenantIcon(Request $request, int $size): Response
    {
        abort_unless(in_array($size, [180, 192, 512], true), 404);
        $tenant = $request->attributes->get('tenant');
        $tenant?->loadMissing('profile');
        $path = $tenant?->profile?->logo_path;
        $contents = null;
        if ($path && str_starts_with($path, '/')) {
            $publicPath = public_path(ltrim($path, '/'));
            $contents = is_file($publicPath) ? file_get_contents($publicPath) : null;
        } elseif ($path && Storage::disk('public')->exists($path)) {
            $contents = Storage::disk('public')->get($path);
        }
        if (! $contents) {
            return response()->file(public_path('icons/icon-'.($size === 180 ? 192 : $size).'.png'), [
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }
        if (! function_exists('imagecreatefromstring')) {
            $imageInfo = @getimagesizefromstring($contents);

            return response($contents, 200, [
                'Content-Type' => $imageInfo['mime'] ?? 'image/png',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }
        $source = @imagecreatefromstring($contents);
        if (! $source) {
            $imageInfo = @getimagesizefromstring($contents);

            return response($contents, 200, [
                'Content-Type' => $imageInfo['mime'] ?? 'image/png',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }
        $canvas = imagecreatetruecolor($size, $size);
        $hex = ltrim((string) ($tenant->profile?->secondary_color ?: '#111318'), '#');
        $background = imagecolorallocate($canvas, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        imagefill($canvas, 0, 0, $background);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $target = (int) round($size * .82);
        $scale = min($target / $sourceWidth, $target / $sourceHeight);
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, (int) (($size - $width) / 2), (int) (($size - $height) / 2), 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        ob_start();
        imagepng($canvas, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function page(string $key): JsonResponse
    {
        $page = PlatformPage::where('key', $key)->where('is_published', true)->firstOrFail();

        return response()->json(['key' => $key, 'title' => $page->localized('title'), 'content' => $this->replaceLegalTokens(LegalContentSanitizer::clean($page->localized('content')))]);
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
        $values = [];
        foreach ($settings as $key => $fallback) {
            $values[str_replace('legal_', '', $key)] = (string) SystemSetting::read($key, $fallback);
        }

        $content = $this->normalizeOptionalLegalSections($content);
        foreach (['representative', 'register', 'vat_id'] as $token) {
            $content = preg_replace_callback(
                '~\{\{#'.$token.'\}\}(.*?)\{\{/'.$token.'\}\}~isu',
                fn (array $match): string => blank($values[$token] ?? null) ? '' : $match[1],
                $content,
            ) ?? $content;

            if (blank($values[$token] ?? null)) {
                $content = $this->removeEmptyOptionalField($content, $token);
            }
        }

        $tokens = [];
        foreach ($values as $token => $value) {
            $tokens['{{'.$token.'}}'] = $token === 'operator_address' ? nl2br(e($value)) : e($value);
        }

        return strtr($content, $tokens);
    }

    private function normalizeOptionalLegalSections(string $content): string
    {
        return strtr($content, [
            '<p>Vertreten durch: {{representative}}</p>' => '{{#representative}}<h2>Vertretungsberechtigte Person</h2><p>{{representative}}</p>{{/representative}}',
            '<p>Represented by: {{representative}}</p>' => '{{#representative}}<h2>Authorized representative</h2><p>{{representative}}</p>{{/representative}}',
            '<p>Уполномоченный представитель: {{representative}}</p>' => '{{#representative}}<h2>Уполномоченный представитель</h2><p>{{representative}}</p>{{/representative}}',
            '<p>Уповноважений представник: {{representative}}</p>' => '{{#representative}}<h2>Уповноважений представник</h2><p>{{representative}}</p>{{/representative}}',
            '<h2>Register und Steuerangaben</h2><p>{{register}}<br>{{vat_id}}</p>' => '{{#register}}<h2>Registereintrag</h2><p>{{register}}</p>{{/register}}{{#vat_id}}<h2>Umsatzsteuer-ID</h2><p>{{vat_id}}</p>{{/vat_id}}',
            '<h2>Register and tax information</h2><p>{{register}}<br>{{vat_id}}</p>' => '{{#register}}<h2>Register entry</h2><p>{{register}}</p>{{/register}}{{#vat_id}}<h2>VAT ID</h2><p>{{vat_id}}</p>{{/vat_id}}',
            '<h2>Регистрация и налоговые сведения</h2><p>{{register}}<br>{{vat_id}}</p>' => '{{#register}}<h2>Запись в реестре</h2><p>{{register}}</p>{{/register}}{{#vat_id}}<h2>Идентификатор плательщика НДС</h2><p>{{vat_id}}</p>{{/vat_id}}',
            '<h2>Реєстраційні та податкові відомості</h2><p>{{register}}<br>{{vat_id}}</p>' => '{{#register}}<h2>Запис у реєстрі</h2><p>{{register}}</p>{{/register}}{{#vat_id}}<h2>Ідентифікатор платника ПДВ</h2><p>{{vat_id}}</p>{{/vat_id}}',
        ]);
    }

    private function removeEmptyOptionalField(string $content, string $token): string
    {
        $headings = [
            'representative' => 'Vertretungsberechtigte Person|Vertreten durch|Authorized representative|Represented by|Уполномоченный представитель|Уповноважений представник',
            'register' => 'Registereintrag|Handelsregister|Register entry|Запись в реестре|Запис у реєстрі',
            'vat_id' => 'Umsatzsteuer-ID|USt-IdNr\.?|VAT ID|Идентификатор плательщика НДС|Ідентифікатор платника ПДВ',
        ];
        $placeholder = preg_quote('{{'.$token.'}}', '~');
        $heading = $headings[$token];
        $content = preg_replace(
            '~<h([1-6])\b[^>]*>[^<]*(?:'.$heading.')[^<]*</h\1>\s*<(p|address)\b[^>]*>.*?'.$placeholder.'.*?</\2>\s*~isu',
            '',
            $content,
        ) ?? $content;

        return preg_replace_callback(
            '~<(p|address)\b[^>]*>.*?</\1>~isu',
            fn (array $match): string => str_contains($match[0], '{{'.$token.'}}') ? '' : $match[0],
            $content,
        ) ?? $content;
    }
}

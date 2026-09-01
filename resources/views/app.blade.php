<!doctype html>
<html lang="{{ app()->getLocale() }}" data-tenant-host="{{ request()->attributes->has('tenant') ? 'true' : 'false' }}">
<head>
    @php
        $socialTenant = request()->attributes->get('tenant');
        if ($socialTenant) {
            $socialTenant->loadMissing('profile');
        }
        $socialLocaleCode = app()->getLocale();
        $socialProfile = $socialTenant?->profile;
        $socialBranding = (array) data_get($socialProfile?->content, 'branding', []);
        $localizedSocialValue = static function (mixed $value, string $locale): ?string {
            if (is_string($value)) {
                return filled($value) ? $value : null;
            }
            if (! is_array($value)) {
                return null;
            }

            return $value[$locale] ?? $value['de'] ?? $value['en'] ?? $value['ru'] ?? reset($value) ?: null;
        };
        $socialTitle = $socialTenant ? $socialTenant->name : 'LOOKDO — LOOK. DO.';
        $platformDescriptions = [
            'de' => 'Der Helfer für Handwerker, die selbstständig arbeiten. Kunden senden Fotos oder Videos und Sie antworten direkt.',
            'en' => 'The helper for tradespeople who work on their own. Customers send photos or videos and you reply right away.',
            'ru' => 'Помощник для мастера, который работает сам на себя. Клиенты отправляют фото или видео, а вы сразу отвечаете.',
            'uk' => 'Помічник для майстра, який працює сам на себе. Клієнти надсилають фото або відео, а ви одразу відповідаєте.',
        ];
        $socialDescription = $localizedSocialValue($socialBranding['description_translations'] ?? null, $socialLocaleCode)
            ?: $socialTenant?->business_description
            ?: ($platformDescriptions[$socialLocaleCode] ?? $platformDescriptions['de']);
        $defaultSocialImages = [
            'de' => '/brand/lookdo-social-de.jpg',
            'en' => '/brand/lookdo-social-en.jpg',
            'ru' => '/brand/lookdo-social-ru.jpg',
            'uk' => '/brand/lookdo-social-uk.jpg',
        ];
        $platformSocialImages = \App\Models\SystemSetting::read('social_share_images', $defaultSocialImages);
        $configuredSocialImage = \App\Models\SystemSetting::read('social_share_image_url', '');
        if (is_array($platformSocialImages)) {
            foreach ($defaultSocialImages as $imageLocale => $currentDefault) {
                if (($platformSocialImages[$imageLocale] ?? null) === "/brand/lookdo-social-{$imageLocale}.png") {
                    $platformSocialImages[$imageLocale] = $currentDefault;
                }
            }
        }
        $platformSocialImage = is_array($platformSocialImages)
            ? ($platformSocialImages[$socialLocaleCode] ?? $defaultSocialImages[$socialLocaleCode] ?? $defaultSocialImages['de'])
            : ($defaultSocialImages[$socialLocaleCode] ?? $defaultSocialImages['de']);
        $standardWorkspaceImages = ['/brand/lookdo-service-workspace.png', '/brand/lookdo-service-workspace.webp'];
        if (filled($configuredSocialImage) && ! in_array($configuredSocialImage, $standardWorkspaceImages, true)) {
            $platformSocialImage = $configuredSocialImage;
        }
        $tenantSocialImage = $socialProfile?->social_image_path
            ?: ($socialBranding['horizontal_logo_path'] ?? null)
            ?: ($socialBranding['hero_image_path'] ?? null)
            ?: $socialProfile?->logo_path;
        $socialImageValue = $tenantSocialImage ?: $platformSocialImage;
        if (filled($socialImageValue) && ! str_starts_with((string) $socialImageValue, 'http') && ! str_starts_with((string) $socialImageValue, '/')) {
            $socialImageValue = '/storage/'.ltrim((string) $socialImageValue, '/');
        }
        $socialOrigin = request()->getSchemeAndHttpHost();
        $socialImage = str_starts_with((string) $socialImageValue, 'http')
            ? $socialImageValue
            : $socialOrigin.'/'.ltrim((string) $socialImageValue, '/');
        $socialUrl = request()->url();
        $socialLocale = ['de' => 'de_DE', 'en' => 'en_GB', 'ru' => 'ru_RU', 'uk' => 'uk_UA'][$socialLocaleCode] ?? 'de_DE';
        $tenantIconVersion = ($socialProfile?->updated_at?->timestamp ?: 1).'-'.(@filemtime(app_path('Http/Controllers/PlatformController.php')) ?: 1);
        $tenantChromeColor = preg_match('/^#[0-9a-f]{6}$/i', (string) $socialProfile?->secondary_color)
            ? $socialProfile->secondary_color
            : '#111318';
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $socialTenant ? $tenantChromeColor : '#ff6a00' }}">
    @if($socialTenant)
        <style>html[data-tenant-host="true"],html[data-tenant-host="true"] body,html[data-tenant-host="true"] #app{background:{{ $tenantChromeColor }}!important}</style>
    @endif
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ $socialTenant?->name ?: 'LOOKDO' }}">
    <meta name="description" content="{{ $socialDescription }}">
    @if(request()->is('app*') || request()->is('control*') || request()->is('login') || request()->is('register') || request()->is('reset-password*'))
        <meta name="robots" content="noindex,nofollow">
    @else
        <meta name="robots" content="index,follow,max-image-preview:large">
    @endif
    <meta property="og:title" content="{{ $socialTitle }}">
    <meta property="og:description" content="{{ $socialDescription }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $socialUrl }}">
    <meta property="og:site_name" content="{{ $socialTenant?->name ?: 'LOOKDO' }}">
    <meta property="og:locale" content="{{ $socialLocale }}">
    <meta property="og:image" content="{{ $socialImage }}">
    <meta property="og:image:secure_url" content="{{ $socialImage }}">
    <meta property="og:image:alt" content="{{ $socialTitle }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $socialTitle }}">
    <meta name="twitter:description" content="{{ $socialDescription }}">
    <meta name="twitter:image" content="{{ $socialImage }}">
    <link rel="canonical" href="{{ $socialUrl }}">
    <link rel="alternate" hreflang="de" href="{{ rtrim(config('app.url'), '/') }}/de">
    <link rel="alternate" hreflang="en" href="{{ rtrim(config('app.url'), '/') }}/en">
    <link rel="alternate" hreflang="ru" href="{{ rtrim(config('app.url'), '/') }}/ru">
    <link rel="alternate" hreflang="uk" href="{{ rtrim(config('app.url'), '/') }}/uk">
    <link rel="alternate" hreflang="x-default" href="{{ rtrim(config('app.url'), '/') }}/de">
    <link rel="icon" href="/tenant-icon/192.png?v={{ $tenantIconVersion }}" type="image/png">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" sizes="180x180" href="/tenant-icon/180.png?v={{ $tenantIconVersion }}">
    <title>{{ $socialTitle }}</title>
    <script type="application/ld+json">{!! json_encode([
        chr(64).'context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'LOOKDO',
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'url' => rtrim(config('app.url'), '/'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @if($socialTenant)
        @vite('resources/js/app.ts')
    @else
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @endif
</head>
<body><div id="app"></div></body>
</html>

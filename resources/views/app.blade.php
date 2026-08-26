<!doctype html>
<html lang="{{ app()->getLocale() }}" data-tenant-host="{{ request()->attributes->has('tenant') ? 'true' : 'false' }}">
<head>
    @php
        $socialTenant = request()->attributes->get('tenant');
        if ($socialTenant) {
            $socialTenant->loadMissing('profile');
        }
        $socialLocaleCode = app()->getLocale();
        $socialTitle = $socialTenant ? $socialTenant->name.' — LOOKDO' : 'LOOKDO — LOOK. DO.';
        $platformDescriptions = [
            'de' => 'Der Helfer für Handwerker, die selbstständig arbeiten. Kunden senden Fotos oder Videos und Sie antworten direkt.',
            'en' => 'The helper for tradespeople who work on their own. Customers send photos or videos and you reply right away.',
            'ru' => 'Помощник для мастера, который работает сам на себя. Клиенты отправляют фото или видео, а вы сразу отвечаете.',
            'uk' => 'Помічник для майстра, який працює сам на себе. Клієнти надсилають фото або відео, а ви одразу відповідаєте.',
        ];
        $socialDescription = $socialTenant?->business_description ?: ($platformDescriptions[$socialLocaleCode] ?? $platformDescriptions['de']);
        $defaultSocialImages = [
            'de' => '/brand/lookdo-social-de.png',
            'en' => '/brand/lookdo-social-en.png',
            'ru' => '/brand/lookdo-social-ru.png',
            'uk' => '/brand/lookdo-social-uk.png',
        ];
        $platformSocialImages = \App\Models\SystemSetting::read('social_share_images', $defaultSocialImages);
        $legacySocialImage = \App\Models\SystemSetting::read('social_share_image_url', '');
        $platformSocialImage = is_array($platformSocialImages)
            ? ($platformSocialImages[$socialLocaleCode] ?? $defaultSocialImages[$socialLocaleCode] ?? $defaultSocialImages['de'])
            : ($defaultSocialImages[$socialLocaleCode] ?? $defaultSocialImages['de']);
        if (filled($legacySocialImage) && $legacySocialImage !== '/brand/lookdo-service-workspace.png') {
            $platformSocialImage = $legacySocialImage;
        }
        $socialImageValue = $socialTenant?->profile?->social_image_path
            ? '/storage/'.ltrim($socialTenant->profile->social_image_path, '/')
            : $platformSocialImage;
        $socialOrigin = request()->getSchemeAndHttpHost();
        $socialImage = str_starts_with((string) $socialImageValue, 'http')
            ? $socialImageValue
            : $socialOrigin.'/'.ltrim((string) $socialImageValue, '/');
        $socialUrl = request()->url();
        $socialLocale = ['de' => 'de_DE', 'en' => 'en_GB', 'ru' => 'ru_RU', 'uk' => 'uk_UA'][$socialLocaleCode] ?? 'de_DE';
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#ff6a00">
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
    <meta property="og:site_name" content="LOOKDO">
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
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="/build/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <title>{{ $socialTitle }}</title>
    <script type="application/ld+json">{!! json_encode([
        chr(64).'context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'LOOKDO',
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'url' => rtrim(config('app.url'), '/'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @vite(['resources/css/app.css','resources/js/app.ts'])
</head>
<body><div id="app"></div></body>
</html>

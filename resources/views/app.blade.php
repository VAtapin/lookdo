<!doctype html>
<html lang="{{ app()->getLocale() }}" data-tenant-host="{{ request()->attributes->has('tenant') ? 'true' : 'false' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#ff6a00">
    <meta name="description" content="LOOKDO — Kunden zeigen ihre Aufgabe mit Fotos oder Video. Fachbetriebe sehen sie und antworten persönlich.">
    @if(request()->is('app*') || request()->is('control*') || request()->is('login') || request()->is('register') || request()->is('reset-password*'))
        <meta name="robots" content="noindex,nofollow">
    @else
        <meta name="robots" content="index,follow,max-image-preview:large">
    @endif
    <meta property="og:title" content="LOOKDO — LOOK. DO.">
    <meta property="og:description" content="Die visuelle Anfrage-App für Servicebetriebe.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ rtrim(config('app.url'), '/') }}{{ request()->path() === '/' ? '' : '/'.request()->path() }}">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}{{ request()->path() === '/' ? '' : '/'.request()->path() }}">
    <link rel="alternate" hreflang="de" href="{{ rtrim(config('app.url'), '/') }}/de">
    <link rel="alternate" hreflang="en" href="{{ rtrim(config('app.url'), '/') }}/en">
    <link rel="alternate" hreflang="ru" href="{{ rtrim(config('app.url'), '/') }}/ru">
    <link rel="alternate" hreflang="uk" href="{{ rtrim(config('app.url'), '/') }}/uk">
    <link rel="alternate" hreflang="x-default" href="{{ rtrim(config('app.url'), '/') }}/de">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="/build/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <title>LOOKDO — LOOK. DO.</title>
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
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

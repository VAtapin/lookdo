<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next, ?string $locale = null): Response
    {
        $pathLocale = $request->segment(1);
        $candidate = $locale
            ?: $request->header('X-Locale')
            ?: $request->route('locale')
            ?: (in_array($pathLocale, ['de', 'en', 'ru', 'uk'], true) ? $pathLocale : null)
            ?: $request->user()?->locale
            ?: session('locale', config('app.locale'));
        if (! in_array($candidate, ['de', 'en', 'ru', 'uk'], true)) {
            $candidate = 'de';
        } app()->setLocale($candidate);
        session(['locale' => $candidate]);

        return $next($request);
    }
}

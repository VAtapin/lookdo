<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next, ?string $locale = null): Response
    {
        $candidate = $locale ?: $request->header('X-Locale') ?: $request->route('locale') ?: $request->user()?->locale ?: session('locale', config('app.locale'));
        if (! in_array($candidate, ['de', 'en', 'ru'], true)) {
            $candidate = 'de';
        } app()->setLocale($candidate);
        session(['locale' => $candidate]);

        return $next($request);
    }
}

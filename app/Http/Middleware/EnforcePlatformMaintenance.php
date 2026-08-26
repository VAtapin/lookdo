<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforcePlatformMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) SystemSetting::read('maintenance', false)) {
            return $next($request);
        }

        if ($request->user()?->is_super_admin || $this->mustRemainAvailable($request)) {
            return $next($request);
        }

        $locale = $this->localeFromPath($request);
        app()->setLocale($locale);

        if ($request->is('api/*') || $request->expectsJson() || ! $request->isMethod('GET')) {
            return response()->json([
                'message' => $this->messages()[$locale]['text'],
                'maintenance' => true,
            ], 503, ['Retry-After' => '3600']);
        }

        return response()->view('maintenance', [
            'locale' => $locale,
            'copy' => $this->messages()[$locale],
        ], 503, ['Retry-After' => '3600']);
    }

    private function mustRemainAvailable(Request $request): bool
    {
        return $request->is('up')
            || $request->is('control*')
            || $request->is('login')
            || $request->is('*/login')
            || $request->is('reset-password*')
            || $request->is('api/login')
            || $request->is('api/logout')
            || $request->is('api/me')
            || $request->is('api/forgot-password')
            || $request->is('api/reset-password')
            || $request->is('api/control*')
            || $request->is('api/stripe/webhook')
            || $request->is('api/webhooks/seven/sms');
    }

    private function localeFromPath(Request $request): string
    {
        $segment = strtolower((string) $request->segment(1));

        return in_array($segment, ['de', 'en', 'ru', 'uk'], true) ? $segment : 'de';
    }

    /** @return array<string, array{eyebrow:string,title:string,text:string,note:string}> */
    private function messages(): array
    {
        return [
            'de' => ['eyebrow' => 'WARTUNGSARBEITEN', 'title' => 'Wir sind gleich wieder da.', 'text' => 'LOOKDO wird gerade aktualisiert. Bitte versuchen Sie es in Kürze erneut.', 'note' => 'Ihre Daten und bereits eingegangenen Anfragen bleiben sicher.'],
            'en' => ['eyebrow' => 'MAINTENANCE', 'title' => 'We will be back shortly.', 'text' => 'LOOKDO is currently being updated. Please try again in a little while.', 'note' => 'Your data and existing requests remain safe.'],
            'ru' => ['eyebrow' => 'ТЕХНИЧЕСКИЕ РАБОТЫ', 'title' => 'Мы скоро вернёмся.', 'text' => 'Сейчас LOOKDO обновляется. Пожалуйста, попробуйте открыть сайт немного позже.', 'note' => 'Ваши данные и уже полученные заявки сохранены.'],
            'uk' => ['eyebrow' => 'ТЕХНІЧНІ РОБОТИ', 'title' => 'Ми скоро повернемося.', 'text' => 'Зараз LOOKDO оновлюється. Будь ласка, спробуйте відкрити сайт трохи пізніше.', 'note' => 'Ваші дані та вже отримані заявки збережені.'],
        ];
    }
}
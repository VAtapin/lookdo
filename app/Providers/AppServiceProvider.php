<?php

namespace App\Providers;

use App\Models\TenantMessage;
use App\Observers\TenantMessageObserver;
use App\Support\CurrentTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class, fn () => new CurrentTenant);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TenantMessage::observe(TenantMessageObserver::class);

        RateLimiter::for('registration-transcription', function (Request $request): array {
            $sessionId = $request->hasSession() ? $request->session()->getId() : '';
            $browserKey = $sessionId !== '' ? hash('sha256', $sessionId) : $request->ip();

            return [
                Limit::perMinute(12)->by('registration-transcription:browser:'.$browserKey),
                Limit::perMinute(120)->by('registration-transcription:ip:'.$request->ip()),
            ];
        });
    }
}

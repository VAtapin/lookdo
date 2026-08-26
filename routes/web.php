<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\SevenSmsWebhookController;
use App\Http\Controllers\SmsAdminController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->middleware('locale')->group(function () {
    Route::get('/platform', [PlatformController::class, 'bootstrap']);
    Route::get('/tenant-site', [PlatformController::class, 'tenantSite']);
    Route::get('/platform/pages/{key}', [PlatformController::class, 'page'])->whereIn('key', ['impressum', 'datenschutz', 'agb', 'kontakt']);
    Route::post('/classify', [AuthController::class, 'classify'])->middleware('throttle:30,1');
    Route::post('/register/availability', [AuthController::class, 'availability'])->middleware('throttle:40,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
    Route::post('/stripe/webhook', StripeWebhookController::class);
    Route::post('/webhooks/seven/sms', SevenSmsWebhookController::class)->middleware('throttle:120,1');

    Route::middleware(['auth', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/tenant/{tenant}', [TenantController::class, 'show']);
        Route::put('/tenant/{tenant}/profile', [TenantController::class, 'updateProfile']);
        Route::post('/tenant/{tenant}/social-image', [TenantController::class, 'uploadSocialImage']);
        Route::post('/tenant/{tenant}/social-image/prompt', [TenantController::class, 'prepareSocialImagePrompt'])->middleware('throttle:10,1');
        Route::post('/tenant/{tenant}/social-image/generate', [TenantController::class, 'generateSocialImage'])->middleware('throttle:5,1');
        Route::post('/tenant/{tenant}/image-credits/checkout', [TenantController::class, 'buyImageCredits'])->middleware('throttle:5,1');
        Route::put('/tenant/{tenant}/slug', [TenantController::class, 'updateSlug']);
        Route::post('/tenant/{tenant}/domains', [TenantController::class, 'addDomain']);
        Route::post('/tenant/{tenant}/domains/{domain}/verify', [TenantController::class, 'verifyDomain']);
        Route::delete('/tenant/{tenant}/domains/{domain}', [TenantController::class, 'removeDomain']);
        Route::post('/tenant/{tenant}/checkout', [TenantController::class, 'checkout']);
        Route::post('/impersonation/stop', [AdminController::class, 'stopImpersonation']);

        Route::prefix('control')->middleware('superadmin')->group(function () {
            Route::get('/dashboard', [AdminController::class, 'dashboard']);
            Route::get('/tenants', [AdminController::class, 'tenants']);
            Route::post('/tenants', [AdminController::class, 'createTenant']);
            Route::get('/tenants/{tenant}', [AdminController::class, 'tenant']);
            Route::put('/tenants/{tenant}', [AdminController::class, 'updateTenant']);
            Route::put('/tenants/{tenant}/entitlement', [AdminController::class, 'setOverride']);
            Route::post('/tenants/{tenant}/impersonate', [AdminController::class, 'impersonate']);
            Route::get('/administrators', [AdminController::class, 'administrators']);
            Route::put('/users/{user}', [AdminController::class, 'updateUser']);
            Route::post('/users/{user}/password-reset', [AdminController::class, 'sendPasswordReset']);
            Route::get('/subscriptions', [AdminController::class, 'subscriptions']);
            Route::get('/plans', [AdminController::class, 'plans']);
            Route::get('/plan-entitlements', [AdminController::class, 'planEntitlements']);
            Route::post('/plans/translate', [AdminController::class, 'translatePlan'])->middleware('throttle:20,1');
            Route::post('/plans', [AdminController::class, 'savePlan']);
            Route::put('/plans/{plan}', [AdminController::class, 'savePlan']);
            Route::post('/plans/{plan}/image', [AdminController::class, 'uploadPlanImage']);
            Route::delete('/plans/{plan}/image', [AdminController::class, 'deletePlanImage']);
            Route::post('/plans/{plan}/stripe-sync', [AdminController::class, 'syncPlan']);
            Route::get('/stripe', [AdminController::class, 'stripeStatus']);
            Route::post('/stripe/sync-plans', [AdminController::class, 'syncAllPlans']);
            Route::get('/sms', [SmsAdminController::class, 'index']);
            Route::post('/sms/test', [SmsAdminController::class, 'testConnection'])->middleware('throttle:10,1');
            Route::get('/backups', [AdminController::class, 'backups']);
            Route::post('/backups', [AdminController::class, 'createBackup']);
            Route::post('/backups/{name}/verify', [AdminController::class, 'verifyBackup']);
            Route::delete('/backups/{name}', [AdminController::class, 'deleteBackup']);
            Route::post('/domains/{domain}/verify', [AdminController::class, 'verifyDomain']);
            Route::post('/domains/{domain}/activate', [AdminController::class, 'activateDomain']);
            Route::post('/domains/{domain}/disable', [AdminController::class, 'disableDomain']);
            Route::delete('/domains/{domain}', [AdminController::class, 'deleteDomain']);
            Route::get('/taxonomy', [AdminController::class, 'taxonomy']);
            Route::post('/categories', [AdminController::class, 'saveCategory']);
            Route::put('/categories/{category}', [AdminController::class, 'saveCategory']);
            Route::post('/variations', [AdminController::class, 'saveVariation']);
            Route::put('/variations/{variation}', [AdminController::class, 'saveVariation']);
            Route::put('/templates/{template}/toggle', [AdminController::class, 'toggleTemplate']);
            Route::post('/templates', [AdminController::class, 'saveTemplate']);
            Route::put('/templates/{template}', [AdminController::class, 'saveTemplate']);
            Route::get('/phrases', [AdminController::class, 'phrases']);
            Route::post('/phrases', [AdminController::class, 'savePhrase']);
            Route::put('/phrases/{phrase}', [AdminController::class, 'savePhrase']);
            Route::get('/classifications', [AdminController::class, 'classifications']);
            Route::get('/settings', [AdminController::class, 'settings']);
            Route::put('/settings', [AdminController::class, 'saveSettings']);
            Route::put('/pages/{page}', [AdminController::class, 'savePage']);
            Route::post('/pages/translate', [AdminController::class, 'translatePage'])->middleware('throttle:20,1');
            Route::post('/content-media', [AdminController::class, 'uploadContentMedia']);
            Route::get('/audits', [AdminController::class, 'audits']);
        });
    });
});

Route::get('/sitemap.xml', function () {
    $base = rtrim(config('app.url'), '/');
    $urls = ['/', '/de', '/en', '/ru', '/uk', '/pricing', '/de/pricing', '/en/pricing', '/ru/pricing', '/uk/pricing', '/impressum', '/datenschutz', '/agb', '/kontakt'];

    return response(view('sitemap', ['urls' => array_map(fn ($u) => $base.$u, $urls)]), 200, ['Content-Type' => 'application/xml']);
});
Route::get('/robots.txt', fn () => response("User-agent: *\nAllow: /\nDisallow: /app\nDisallow: /control\nDisallow: /api\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']));
Route::get('/reset-password/{token}', fn () => view('app'))->middleware('locale')->name('password.reset');
Route::view('/{path?}', 'app')->middleware('locale')->where('path', '^(?!api|up|sitemap\.xml|robots\.txt).*$');

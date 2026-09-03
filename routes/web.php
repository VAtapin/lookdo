<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminSubscriptionController;
use App\Http\Controllers\AdminTenantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\SevenSmsWebhookController;
use App\Http\Controllers\SmsAdminController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TenantAppController;
use App\Http\Controllers\TenantCalendarController;
use App\Http\Controllers\TenantContentController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantOperationsController;
use App\Http\Controllers\TenantSocialConnectionController;
use App\Http\Controllers\TenantWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->middleware('locale')->group(function () {
    Route::get('/platform', [PlatformController::class, 'bootstrap']);
    Route::get('/tenant-site', [PlatformController::class, 'tenantSite']);
    Route::prefix('tenant-app')->middleware('throttle:120,1')->group(function () {
        Route::get('/bootstrap', [TenantAppController::class, 'bootstrap']);
        Route::post('/requests', [TenantAppController::class, 'createRequest'])->middleware('throttle:12,1');
        Route::post('/request-assistance', [TenantAppController::class, 'assistRequest'])->middleware('throttle:20,1');
        Route::get('/activity', [TenantAppController::class, 'activity']);
        Route::post('/requests/{tenantRequest}/messages', [TenantAppController::class, 'postMessage'])->middleware('throttle:30,1');
        Route::get('/availability', [TenantAppController::class, 'availability'])->middleware('throttle:60,1');
        Route::post('/appointments', [TenantAppController::class, 'createAppointment'])->middleware('throttle:12,1');
        Route::patch('/appointments/{tenantAppointment}', [TenantAppController::class, 'rescheduleAppointment'])->middleware('throttle:20,1');
        Route::delete('/appointments/{tenantAppointment}', [TenantAppController::class, 'cancelAppointment'])->middleware('throttle:20,1');
        Route::post('/push-subscriptions', [TenantAppController::class, 'subscribePush'])->middleware('throttle:10,1');
        Route::post('/reviews', [TenantAppController::class, 'submitReview'])->middleware('throttle:10,1');
    });
    Route::get('/platform/pages/{key}', [PlatformController::class, 'page'])->whereIn('key', ['impressum', 'datenschutz', 'agb', 'kontakt']);
    Route::post('/classify', [AuthController::class, 'classify'])->middleware('throttle:30,1');
    Route::post('/register/transcribe', [AuthController::class, 'transcribeBusiness'])->middleware('throttle:registration-transcription');
    Route::post('/register/availability', [AuthController::class, 'availability'])->middleware('throttle:40,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
    Route::post('/stripe/webhook', StripeWebhookController::class);
    Route::post('/webhooks/seven/sms', SevenSmsWebhookController::class)->middleware('throttle:120,1');
    Route::get('/social/oauth/{provider}/callback', [TenantSocialConnectionController::class, 'callback'])->name('social.callback');
    Route::post('/webhooks/telegram/social', [TenantSocialConnectionController::class, 'telegramWebhook'])->middleware('throttle:120,1');

    Route::middleware(['auth', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/account', [AuthController::class, 'updateAccount']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/tenant/{tenant}', [TenantController::class, 'show']);
        Route::put('/tenant/{tenant}/profile', [TenantController::class, 'updateProfile']);
        Route::put('/tenant/{tenant}/branding', [TenantController::class, 'updateBranding']);
        Route::post('/tenant/{tenant}/branding/assets', [TenantController::class, 'uploadBrandingAsset']);
        Route::post('/tenant/{tenant}/branding/prompt', [TenantController::class, 'prepareBrandingPrompt'])->middleware('throttle:10,1');
        Route::post('/tenant/{tenant}/branding/generate', [TenantController::class, 'generateBrandingAsset'])->middleware('throttle:5,1');
        Route::post('/tenant/{tenant}/social-image', [TenantController::class, 'uploadSocialImage']);
        Route::post('/tenant/{tenant}/social-image/prompt', [TenantController::class, 'prepareSocialImagePrompt'])->middleware('throttle:10,1');
        Route::post('/tenant/{tenant}/social-image/generate', [TenantController::class, 'generateSocialImage'])->middleware('throttle:5,1');
        Route::post('/tenant/{tenant}/image-credits/checkout', [TenantController::class, 'buyImageCredits'])->middleware('throttle:5,1');
        Route::put('/tenant/{tenant}/slug', [TenantController::class, 'updateSlug']);
        Route::post('/tenant/{tenant}/domains', [TenantController::class, 'addDomain']);
        Route::post('/tenant/{tenant}/domains/{domain}/verify', [TenantController::class, 'verifyDomain']);
        Route::delete('/tenant/{tenant}/domains/{domain}', [TenantController::class, 'removeDomain']);
        Route::post('/tenant/{tenant}/checkout', [TenantController::class, 'checkout']);
        Route::post('/tenant/{tenant}/billing-portal', [TenantController::class, 'billingPortal']);
        Route::get('/tenant/{tenant}/invoices/{invoice}', [TenantController::class, 'invoice']);
        Route::get('/tenant/{tenant}/payments/{payment}/receipt', [TenantController::class, 'paymentReceipt']);
        Route::get('/tenant/{tenant}/export', [TenantController::class, 'exportData']);
        Route::delete('/tenant/{tenant}/account', [TenantController::class, 'destroyOwnAccount']);
        Route::get('/tenant/{tenant}/workspace', [TenantWorkspaceController::class, 'bootstrap']);
        Route::get('/tenant/{tenant}/workspace/requests', [TenantWorkspaceController::class, 'requests']);
        Route::put('/tenant/{tenant}/workspace/requests/{tenantRequest}', [TenantWorkspaceController::class, 'updateRequest']);
        Route::put('/tenant/{tenant}/workspace/appointments/{tenantAppointment}', [TenantWorkspaceController::class, 'updateAppointment']);
        Route::post('/tenant/{tenant}/workspace/requests/{tenantRequest}/read', [TenantWorkspaceController::class, 'markRequestRead']);
        Route::post('/tenant/{tenant}/workspace/requests/{tenantRequest}/reply', [TenantWorkspaceController::class, 'reply']);
        Route::get('/tenant/{tenant}/workspace/conversations', [TenantWorkspaceController::class, 'conversations']);
        Route::get('/tenant/{tenant}/workspace/customers', [TenantWorkspaceController::class, 'customers']);
        Route::get('/tenant/{tenant}/workspace/customers/{customer}', [TenantWorkspaceController::class, 'customer']);
        Route::put('/tenant/{tenant}/workspace/customers/{customer}', [TenantWorkspaceController::class, 'updateCustomer']);
        Route::post('/tenant/{tenant}/workspace/customers/{customer}/merge', [TenantWorkspaceController::class, 'mergeCustomer']);
        Route::post('/tenant/{tenant}/workspace/push-subscriptions', [TenantWorkspaceController::class, 'subscribePush'])->middleware('throttle:10,1');
        Route::delete('/tenant/{tenant}/workspace/push-subscriptions', [TenantWorkspaceController::class, 'unsubscribePush'])->middleware('throttle:10,1');
        Route::get('/tenant/{tenant}/workspace/team', [TenantWorkspaceController::class, 'team']);
        Route::post('/tenant/{tenant}/workspace/team', [TenantWorkspaceController::class, 'addTeamMember']);
        Route::put('/tenant/{tenant}/workspace/team/{user}', [TenantWorkspaceController::class, 'updateTeamMember']);
        Route::post('/tenant/{tenant}/workspace/team/{user}/setup-link', [TenantWorkspaceController::class, 'teamMemberSetupLink']);
        Route::delete('/tenant/{tenant}/workspace/team/{user}', [TenantWorkspaceController::class, 'removeTeamMember']);
        Route::get('/tenant/{tenant}/workspace/resources', [TenantOperationsController::class, 'resources']);
        Route::post('/tenant/{tenant}/workspace/resources', [TenantOperationsController::class, 'saveResource']);
        Route::put('/tenant/{tenant}/workspace/resources/{resource}', [TenantOperationsController::class, 'saveResource']);
        Route::delete('/tenant/{tenant}/workspace/resources/{resource}', [TenantOperationsController::class, 'deleteResource']);
        Route::get('/tenant/{tenant}/workspace/segments', [TenantOperationsController::class, 'segments']);
        Route::post('/tenant/{tenant}/workspace/segments', [TenantOperationsController::class, 'saveSegment']);
        Route::put('/tenant/{tenant}/workspace/segments/{segment}', [TenantOperationsController::class, 'saveSegment']);
        Route::delete('/tenant/{tenant}/workspace/segments/{segment}', [TenantOperationsController::class, 'deleteSegment']);
        Route::put('/tenant/{tenant}/workspace/customers/{customer}/segments', [TenantOperationsController::class, 'syncCustomerSegments']);
        Route::get('/tenant/{tenant}/workspace/vacancy-candidates', [TenantOperationsController::class, 'vacancyCandidates']);

        Route::get('/tenant/{tenant}/calendar', [TenantCalendarController::class, 'index']);
        Route::put('/tenant/{tenant}/calendar/working-hours', [TenantCalendarController::class, 'saveWorkingHours']);
        Route::get('/tenant/{tenant}/calendar/slots', [TenantCalendarController::class, 'slots']);
        Route::post('/tenant/{tenant}/services', [TenantCalendarController::class, 'saveService']);
        Route::put('/tenant/{tenant}/services/{service}', [TenantCalendarController::class, 'saveService']);
        Route::post('/tenant/{tenant}/services/{service}/translate', [TenantCalendarController::class, 'translateService'])->middleware('throttle:20,1');
        Route::post('/tenant/{tenant}/services/{service}/image', [TenantCalendarController::class, 'uploadServiceImage']);
        Route::delete('/tenant/{tenant}/services/{service}/image', [TenantCalendarController::class, 'removeServiceImage']);
        Route::delete('/tenant/{tenant}/services/{service}', [TenantCalendarController::class, 'deleteService']);
        Route::post('/tenant/{tenant}/calendar/appointments', [TenantCalendarController::class, 'saveAppointment']);
        Route::put('/tenant/{tenant}/calendar/appointments/{appointment}', [TenantCalendarController::class, 'saveAppointment']);
        Route::delete('/tenant/{tenant}/calendar/appointments/{appointment}', [TenantCalendarController::class, 'deleteAppointment']);
        Route::post('/tenant/{tenant}/calendar/blocks', [TenantCalendarController::class, 'saveBlock']);
        Route::put('/tenant/{tenant}/calendar/blocks/{block}', [TenantCalendarController::class, 'saveBlock']);
        Route::delete('/tenant/{tenant}/calendar/blocks/{block}', [TenantCalendarController::class, 'deleteBlock']);
        Route::post('/tenant/{tenant}/calendar/reminders', [TenantCalendarController::class, 'saveReminder']);
        Route::put('/tenant/{tenant}/calendar/reminders/{reminder}', [TenantCalendarController::class, 'saveReminder']);
        Route::delete('/tenant/{tenant}/calendar/reminders/{reminder}', [TenantCalendarController::class, 'deleteReminder']);

        Route::get('/tenant/{tenant}/content-workspace', [TenantContentController::class, 'index']);
        Route::post('/tenant/{tenant}/portfolio', [TenantContentController::class, 'savePortfolio']);
        Route::post('/tenant/{tenant}/portfolio/{item}', [TenantContentController::class, 'savePortfolio']);
        Route::put('/tenant/{tenant}/portfolio/{item}', [TenantContentController::class, 'savePortfolio']);
        Route::delete('/tenant/{tenant}/portfolio/{item}', [TenantContentController::class, 'deletePortfolio']);
        Route::post('/tenant/{tenant}/reviews', [TenantContentController::class, 'saveReview']);
        Route::put('/tenant/{tenant}/reviews/{review}', [TenantContentController::class, 'saveReview']);
        Route::delete('/tenant/{tenant}/reviews/{review}', [TenantContentController::class, 'deleteReview']);
        Route::post('/tenant/{tenant}/social-drafts', [TenantContentController::class, 'saveSocial']);
        Route::put('/tenant/{tenant}/social-drafts/{draft}', [TenantContentController::class, 'saveSocial']);
        Route::delete('/tenant/{tenant}/social-drafts/{draft}', [TenantContentController::class, 'deleteSocial']);
        Route::post('/tenant/{tenant}/social-drafts/{draft}/publish', [TenantSocialConnectionController::class, 'publish'])->middleware('throttle:10,1');
        Route::post('/tenant/{tenant}/social-connections/{provider}/authorize', [TenantSocialConnectionController::class, 'authorizeProvider'])->middleware('throttle:10,1');
        Route::put('/tenant/{tenant}/social-providers/{provider}', [TenantSocialConnectionController::class, 'saveProviderConfig'])->middleware('throttle:10,1');
        Route::delete('/tenant/{tenant}/social-connections/{provider}', [TenantSocialConnectionController::class, 'disconnect']);
        Route::post('/tenant/{tenant}/workspace/ai', [TenantContentController::class, 'ai'])->middleware('throttle:20,1');
        Route::post('/impersonation/stop', [AdminController::class, 'stopImpersonation']);

        Route::prefix('control')->middleware('superadmin')->group(function () {
            Route::get('/dashboard', [AdminController::class, 'dashboard']);
            Route::get('/tenants', [AdminTenantController::class, 'index']);
            Route::post('/tenants', [AdminTenantController::class, 'store']);
            Route::get('/tenants/{tenant}', [AdminTenantController::class, 'show']);
            Route::put('/tenants/{tenant}', [AdminTenantController::class, 'update']);
            Route::delete('/tenants/{tenant}', [AdminTenantController::class, 'destroy']);
            Route::put('/tenants/{tenant}/owner', [AdminTenantController::class, 'updateOwner']);
            Route::post('/tenants/{tenant}/owner/password-reset', [AdminTenantController::class, 'sendOwnerPasswordReset']);
            Route::post('/tenants/{tenant}/domains/{domain}/verify', [AdminTenantController::class, 'verifyDomain']);
            Route::post('/tenants/{tenant}/domains/{domain}/activate', [AdminTenantController::class, 'activateDomain']);
            Route::post('/tenants/{tenant}/domains/{domain}/disable', [AdminTenantController::class, 'disableDomain']);
            Route::delete('/tenants/{tenant}/domains/{domain}', [AdminTenantController::class, 'deleteDomain']);
            Route::post('/tenants/{tenant}/grant-access', [AdminTenantController::class, 'grantAccess']);
            Route::put('/tenants/{tenant}/entitlement', [AdminTenantController::class, 'setOverride']);
            Route::post('/tenants/{tenant}/impersonate', [AdminTenantController::class, 'impersonate']);
            Route::get('/administrators', [AdminController::class, 'administrators']);
            Route::post('/administrators', [AdminController::class, 'storeAdministrator']);
            Route::put('/administrators/{administrator}', [AdminController::class, 'updateAdministrator']);
            Route::delete('/administrators/{administrator}', [AdminController::class, 'destroyAdministrator']);
            Route::get('/subscriptions', [AdminController::class, 'subscriptions']);
            Route::get('/subscriptions/{subscription}', [AdminSubscriptionController::class, 'show']);
            Route::patch('/subscriptions/{subscription}/status', [AdminSubscriptionController::class, 'updateStatus']);
            Route::post('/subscriptions/{subscription}/payments', [AdminSubscriptionController::class, 'storePayment']);
            Route::get('/subscriptions/{subscription}/payments/{payment}/receipt', [AdminSubscriptionController::class, 'receipt']);
            Route::post('/subscriptions/{subscription}/invoices', [AdminSubscriptionController::class, 'storeInvoice']);
            Route::patch('/subscriptions/{subscription}/invoices/{invoice}', [AdminSubscriptionController::class, 'updateInvoice']);
            Route::get('/subscriptions/{subscription}/invoices/{invoice}', [AdminSubscriptionController::class, 'invoice']);
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
            Route::post('/openai/test', [AdminController::class, 'testOpenAiUsage'])->middleware('throttle:10,1');
            Route::get('/backups', [AdminController::class, 'backups']);
            Route::post('/backups', [AdminController::class, 'createBackup']);
            Route::post('/backups/tenants/{tenant}', [AdminController::class, 'createTenantBackup']);
            Route::post('/backups/tenants/{tenant}/{name}/verify', [AdminController::class, 'verifyTenantBackup']);
            Route::post('/backups/tenants/{tenant}/{name}/restore', [AdminController::class, 'restoreTenantBackup']);
            Route::delete('/backups/tenants/{tenant}/{name}', [AdminController::class, 'deleteTenantBackup']);
            Route::post('/backups/{name}/verify', [AdminController::class, 'verifyBackup']);
            Route::delete('/backups/{name}', [AdminController::class, 'deleteBackup']);
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
            Route::delete('/audits', [AdminController::class, 'clearAudits']);
        });
    });
});

Route::get('/manifest.webmanifest', [PlatformController::class, 'manifest']);
Route::get('/tenant-icon/{size}.png', [PlatformController::class, 'tenantIcon'])->whereIn('size', ['180', '192', '512']);
Route::get('/sw.js', function () {
    $path = public_path('build/sw.js');
    abort_unless(is_file($path), 404);

    $source = file_get_contents($path);
    $source = preg_replace_callback(
        '~importScripts\("(?!https?://|/)([^"]+)"\)~',
        fn (array $match): string => 'importScripts("/build/'.ltrim($match[1], '/').'")',
        $source,
    ) ?? $source;
    $source = preg_replace(
        '~define\(\["\./(workbox-[^"]+)"\]~',
        'define(["/build/$1"]',
        $source,
    ) ?? $source;
    $source = str_replace('url:"assets/', 'url:"/build/assets/', $source);

    return response($source, 200, [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Service-Worker-Allowed' => '/',
    ]);
});

Route::get('/sitemap.xml', function () {
    $base = rtrim(config('app.url'), '/');
    $urls = ['/', '/de', '/en', '/ru', '/uk', '/pricing', '/de/pricing', '/en/pricing', '/ru/pricing', '/uk/pricing', '/impressum', '/datenschutz', '/agb', '/kontakt'];

    return response(view('sitemap', ['urls' => array_map(fn ($u) => $base.$u, $urls)]), 200, ['Content-Type' => 'application/xml']);
});
Route::get('/robots.txt', fn () => response("User-agent: *\nAllow: /\nDisallow: /app\nDisallow: /control\nDisallow: /api\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']));
Route::get('/reset-password/{token}', fn () => view('app'))->middleware('locale')->name('password.reset');
Route::get('/account/email-change/{user}', [AuthController::class, 'confirmEmailChange'])->middleware('signed')->name('account.email-change.confirm');
Route::view('/{path?}', 'app')->middleware('locale')->where('path', '^(?!api|up|sitemap\\.xml|robots\\.txt).*$');

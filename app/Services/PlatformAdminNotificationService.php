<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\TenantRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PlatformAdminNotificationService
{
    public function __construct(
        private readonly TenantWebPushService $webPush,
        private readonly SmsService $sms,
    ) {}

    public function newRequest(Tenant $tenant, TenantRequest $tenantRequest): void
    {
        $channels = (array) SystemSetting::read('admin_notifications', []);
        if (! collect(['push', 'email', 'sms'])->contains(fn (string $channel): bool => (bool) ($channels[$channel] ?? false))) {
            return;
        }

        $tenantRequest->loadMissing('customer');
        $requestNumber = filled($tenantRequest->number) ? $tenantRequest->number : '#'.$tenantRequest->id;
        $customerName = trim((string) $tenantRequest->customer?->name);
        $title = 'Neue Kundenanfrage';
        $body = $tenant->name.' · '.$requestNumber.($customerName !== '' ? ' · '.$customerName : '');
        $url = '/control/tenants';

        if ($channels['push'] ?? false) {
            try {
                $this->webPush->sendToPlatformAdministrators([
                    'title' => $title,
                    'body' => $body,
                    'url' => $url,
                    'tag' => 'lookdo-admin-request-'.$tenantRequest->id,
                    'action' => 'Kunden öffnen',
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $email = trim((string) SystemSetting::read('admin_notification_email', ''));
        if (($channels['email'] ?? false) && $email !== '') {
            try {
                Mail::raw($title."\n\n".$body."\n\n".rtrim((string) config('app.url'), '/').$url, fn ($mail) => $mail->to($email)->subject($title.' · '.$tenant->name));
            } catch (Throwable $exception) {
                Log::warning('Platform admin email notification failed.', ['tenant_id' => $tenant->id, 'request_id' => $tenantRequest->id, 'error' => $exception->getMessage()]);
            }
        }

        $phone = trim((string) SystemSetting::read('admin_notification_phone', ''));
        if (($channels['sms'] ?? false) && $phone !== '') {
            try {
                $this->sms->queuePlatformImportant($tenant, $phone, $title.': '.$body, 'admin-request-'.$tenantRequest->id);
            } catch (Throwable $exception) {
                Log::warning('Platform admin SMS notification failed.', ['tenant_id' => $tenant->id, 'request_id' => $tenantRequest->id, 'error' => $exception->getMessage()]);
            }
        }
    }
}

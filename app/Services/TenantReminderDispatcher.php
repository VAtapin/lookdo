<?php

namespace App\Services;

use App\Models\TenantReminder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class TenantReminderDispatcher
{
    public function __construct(
        private readonly TenantWebPushService $webPush,
        private readonly SmsService $sms,
        private readonly EntitlementService $entitlements,
    ) {}

    /** @return array{processed:int,sent:int,queued:int,manual:int,skipped:int,failed:int} */
    public function dispatchDue(int $limit = 100): array
    {
        $result = ['processed' => 0, 'sent' => 0, 'queued' => 0, 'manual' => 0, 'skipped' => 0, 'failed' => 0];
        $ids = TenantReminder::query()->where('status', 'scheduled')->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')->limit(max(1, min($limit, 500)))->pluck('id');

        foreach ($ids as $id) {
            $reminder = DB::transaction(function () use ($id): ?TenantReminder {
                $record = TenantReminder::query()->lockForUpdate()->find($id);
                if (! $record || $record->status !== 'scheduled') {
                    return null;
                }
                $record->update(['status' => 'processing', 'error' => null]);

                return $record;
            });
            if (! $reminder) {
                continue;
            }
            $result['processed']++;

            try {
                $reminder->load(['tenant', 'customer', 'appointment']);
                $status = $this->deliver($reminder);
                $reminder->update([
                    'status' => $status,
                    'sent_at' => in_array($status, ['sent', 'queued'], true) ? now() : null,
                    'error' => null,
                ]);
                $result[$status]++;
            } catch (Throwable $exception) {
                report($exception);
                $reminder->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000)]);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function deliver(TenantReminder $reminder): string
    {
        $tenant = $reminder->tenant;
        $customer = $reminder->customer;
        if (! $tenant || ! $customer || ! $tenant->hasActiveSubscription()) {
            return 'skipped';
        }
        if (! filter_var($this->entitlements->get($tenant, 'reminders_enabled', false), FILTER_VALIDATE_BOOL)) {
            return 'skipped';
        }

        if ($reminder->channel === 'push') {
            if (! filter_var($this->entitlements->get($tenant, 'push_enabled', true), FILTER_VALIDATE_BOOL)) {
                return 'skipped';
            }
            $delivery = $this->webPush->sendToCustomer($customer, [
                'title' => $tenant->name,
                'body' => $reminder->message,
                'url' => '/activity',
                'tag' => 'lookdo-reminder-'.$reminder->id,
            ]);

            return ($delivery['sent'] ?? 0) > 0 ? 'sent' : 'skipped';
        }

        if ($reminder->channel === 'sms') {
            if (! filled($customer->phone)) {
                return 'skipped';
            }
            try {
                $this->sms->queueImportant($tenant, $customer->phone, $reminder->message, 'agreement_reminder', 'tenant-reminder-'.$reminder->id);
            } catch (DomainException $exception) {
                $reminder->update(['error' => $exception->getMessage()]);

                return 'skipped';
            }

            return 'queued';
        }

        // E-mail and WhatsApp use the deliberate manual share flow.
        // LOOKDO never pretends that an external provider delivered a message.
        return 'manual';
    }
}

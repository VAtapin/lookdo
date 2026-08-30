<?php

namespace App\Services;

use App\Jobs\SendSmsMessage;
use App\Models\SmsMessage;
use App\Models\SystemSetting;
use App\Models\Tenant;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SmsService
{
    public const IMPORTANT_EVENTS = [
        'request_received',
        'master_replied',
        'work_ready',
        'agreement_reminder',
    ];

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly SmsGateway $gateway,
    ) {}

    public function queueImportant(Tenant $tenant, string $recipient, string $message, string $eventType, ?string $idempotencyKey = null): SmsMessage
    {
        if (! in_array($eventType, self::IMPORTANT_EVENTS, true)) {
            throw new DomainException('This event is not allowed to send SMS.');
        }
        $eventSettings = (array) SystemSetting::read('sms_events', []);
        if (! (bool) ($eventSettings[$eventType] ?? false)) {
            throw new DomainException('SMS is disabled for this event.');
        }
        if (! $this->gateway->configured()) {
            throw new DomainException('SMS integration is not configured.');
        }
        if (! filter_var($this->entitlements->get($tenant, 'sms_enabled', false), FILTER_VALIDATE_BOOL)) {
            throw new DomainException('The tenant plan does not include SMS.');
        }
        $limit = max(0, (int) $this->entitlements->get($tenant, 'sms_monthly_limit', 0));
        if ($limit === 0) {
            throw new DomainException('The tenant SMS limit is zero.');
        }
        $recipient = $this->normalizeRecipient($tenant, $recipient);
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 1000) {
            throw new DomainException('SMS text must contain between 1 and 1000 characters.');
        }

        $record = DB::transaction(function () use ($tenant, $recipient, $message, $eventType, $idempotencyKey, $limit): SmsMessage {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if (filled($idempotencyKey)) {
                $existing = SmsMessage::where('tenant_id', $tenant->id)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
            }
            $used = SmsMessage::where('tenant_id', $tenant->id)
                ->where('created_at', '>=', now()->startOfMonth())
                ->whereNotIn('status', ['failed', 'rejected'])
                ->count();
            if ($used >= $limit) {
                throw new DomainException('The monthly SMS limit has been reached.');
            }

            return SmsMessage::create([
                'tenant_id' => $tenant->id,
                'uuid' => (string) Str::uuid(),
                'provider' => $this->gateway->provider(),
                'event_type' => $eventType,
                'recipient' => $recipient,
                'recipient_hash' => hash('sha256', $recipient),
                'message' => $message,
                'idempotency_key' => $idempotencyKey,
                'status' => 'queued',
            ]);
        });

        if ($record->wasRecentlyCreated) {
            DB::afterCommit(fn () => SendSmsMessage::dispatch($record->id));
        }

        return $record;
    }

    public function localizedMessage(Tenant $tenant, string $locale, string $eventType): string
    {
        $locale = in_array($locale, ['de', 'en', 'ru', 'uk'], true) ? $locale : 'de';
        $domain = $tenant->primaryDomain?->domain ?: $tenant->slug.'.'.config('tenancy.platform_domain');

        return trans('tenant_app.sms.'.$eventType, [
            'business' => $tenant->name,
            'url' => 'https://'.$domain.'/activity',
        ], $locale);
    }

    private function normalizeRecipient(Tenant $tenant, string $recipient): string
    {
        $recipient = preg_replace('/[\s().-]+/', '', trim($recipient)) ?: '';
        if (str_starts_with($recipient, '00')) {
            $recipient = '+'.substr($recipient, 2);
        }
        if (! str_starts_with($recipient, '+')) {
            $digits = preg_replace('/\D+/', '', $recipient) ?: '';
            $country = strtoupper((string) ($tenant->country ?: 'DE'));
            $dialCode = ['DE' => '49', 'RU' => '7', 'UA' => '380', 'GB' => '44', 'US' => '1', 'CA' => '1'][$country] ?? null;
            if ($country === 'RU' && strlen($digits) === 11 && str_starts_with($digits, '8')) {
                $digits = '7'.substr($digits, 1);
            } elseif ($dialCode && str_starts_with($digits, '0')) {
                $digits = $dialCode.ltrim($digits, '0');
            } elseif ($dialCode && ! str_starts_with($digits, $dialCode)) {
                $digits = $dialCode.$digits;
            }
            $recipient = '+'.$digits;
        }
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $recipient)) {
            throw new DomainException('The recipient must be in international E.164 format.');
        }

        return $recipient;
    }
}

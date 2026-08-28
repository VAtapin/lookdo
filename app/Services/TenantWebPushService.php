<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\TenantPushSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use JsonException;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class TenantWebPushService
{
    public function configured(): bool
    {
        return filled(config('services.webpush.vapid_public_key'))
            && filled(config('services.webpush.vapid_private_key'))
            && filled(config('services.webpush.subject'));
    }

    /**
     * @param  array{title:string,body:string,url?:string,icon?:string,badge?:string,tag?:string,action?:string}  $payload
     * @return array{sent:int,failed:int,expired:int,skipped:bool}
     */
    public function sendToCustomer(TenantCustomer $customer, array $payload): array
    {
        $subscriptions = TenantPushSubscription::query()
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->get();

        return $this->send($subscriptions, $payload, [
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * @param  array{title:string,body:string,url?:string,icon?:string,badge?:string,tag?:string,action?:string}  $payload
     * @return array{sent:int,failed:int,expired:int,skipped:bool}
     */
    public function sendToTenantUsers(Tenant $tenant, array $payload): array
    {
        $subscriptions = TenantPushSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('user_id')
            ->get();

        return $this->send($subscriptions, $payload, ['tenant_id' => $tenant->id]);
    }

    /**
     * @param  Collection<int, TenantPushSubscription>  $subscriptions
     * @param  array{title:string,body:string,url?:string,icon?:string,badge?:string,tag?:string,action?:string}  $payload
     * @param  array<string, int|string|null>  $context
     * @return array{sent:int,failed:int,expired:int,skipped:bool}
     */
    private function send(Collection $subscriptions, array $payload, array $context): array
    {
        if (! $this->configured() || $subscriptions->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'expired' => 0, 'skipped' => true];
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string) config('services.webpush.subject'),
                'publicKey' => (string) config('services.webpush.vapid_public_key'),
                'privateKey' => (string) config('services.webpush.vapid_private_key'),
            ],
        ], ['TTL' => 86400, 'urgency' => 'high']);

        $payloadJson = $this->payload($payload);
        $byEndpoint = [];

        foreach ($subscriptions as $record) {
            $byEndpoint[$record->endpoint] = $record;
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $record->endpoint,
                'keys' => ['p256dh' => $record->public_key, 'auth' => $record->auth_token],
                'contentEncoding' => ContentEncoding::aes128gcm->value,
            ]), $payloadJson);
        }

        $result = ['sent' => 0, 'failed' => 0, 'expired' => 0, 'skipped' => false];
        foreach ($webPush->flush() as $report) {
            $record = $byEndpoint[$report->getEndpoint()] ?? null;
            if ($report->isSuccess()) {
                $result['sent']++;

                continue;
            }
            if ($report->isSubscriptionExpired()) {
                $record?->delete();
                $result['expired']++;

                continue;
            }
            $result['failed']++;
            Log::warning('Tenant web push delivery failed.', $context + [
                'user_id' => $record?->user_id,
                'customer_id' => $record?->customer_id,
                'endpoint_hash' => $record?->endpoint_hash,
                'reason' => $report->getReason(),
            ]);
        }

        return $result;
    }

    /** @throws JsonException */
    private function payload(array $payload): string
    {
        try {
            return json_encode([
                'title' => $payload['title'],
                'body' => $payload['body'],
                'url' => $payload['url'] ?? '/activity',
                'icon' => $payload['icon'] ?? '/icons/icon-192.png',
                'badge' => $payload['badge'] ?? '/icons/icon-192.png',
                'tag' => $payload['tag'] ?? 'lookdo-message',
                'action' => $payload['action'] ?? 'Open',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new JsonException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Services\SmsGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendSmsMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $smsMessageId) {}

    public function handle(SmsGateway $gateway): void
    {
        $claimed = SmsMessage::query()
            ->whereKey($this->smsMessageId)
            ->where('status', 'queued')
            ->update(['status' => 'sending', 'error_code' => null, 'error_message' => null]);
        if ($claimed !== 1) {
            return;
        }
        $message = SmsMessage::findOrFail($this->smsMessageId);

        try {
            $payload = $gateway->send($message->recipient, $message->message);
            $providerMessage = $payload['messages'][0] ?? [];
            $message->update([
                'status' => 'accepted',
                'provider_status' => 'ACCEPTED',
                'provider_message_id' => (string) ($providerMessage['id'] ?? ''),
                'parts' => max(1, (int) ($providerMessage['parts'] ?? 1)),
                'cost' => (float) ($payload['total_price'] ?? $providerMessage['price'] ?? 0),
                'currency' => 'EUR',
                'provider_payload' => $payload,
                'sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $message->update([
                'status' => 'failed',
                'provider_status' => 'FAILED',
                'error_code' => (string) $exception->getCode(),
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'failed_at' => now(),
            ]);
        }
    }
}

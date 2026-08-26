<?php

namespace App\Http\Controllers;

use App\Models\SmsMessage;
use App\Services\SmsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SevenSmsWebhookController extends Controller
{
    public function __invoke(Request $request, SmsGateway $gateway): JsonResponse
    {
        abort_unless($gateway->validWebhook($request), 400, 'Invalid seven.io webhook signature.');
        $payload = $request->json()->all();
        if (($payload['webhook_event'] ?? null) !== 'dlr') {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $providerMessageId = (string) data_get($payload, 'data.msg_id', '');
        $providerStatus = strtoupper((string) data_get($payload, 'data.status', ''));
        $message = SmsMessage::where('provider', 'seven')->where('provider_message_id', $providerMessageId)->first();
        if (! $message) {
            return response()->json(['received' => true, 'matched' => false]);
        }

        $status = match ($providerStatus) {
            'DELIVERED' => 'delivered',
            'NOTDELIVERED', 'EXPIRED', 'REJECTED', 'FAILED' => 'failed',
            default => 'accepted',
        };
        $providerTimestamp = data_get($payload, 'data.timestamp');
        $occurredAt = $providerTimestamp ? Carbon::parse((string) $providerTimestamp) : now();
        $message->update([
            'status' => $status,
            'provider_status' => $providerStatus,
            'provider_payload' => $payload,
            'delivered_at' => $status === 'delivered' ? $occurredAt : $message->delivered_at,
            'failed_at' => $status === 'failed' ? $occurredAt : $message->failed_at,
        ]);

        return response()->json(['received' => true, 'matched' => true]);
    }
}

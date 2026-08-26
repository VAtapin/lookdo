<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SmsGateway
{
    public function configured(): bool
    {
        return $this->enabled()
            && $this->provider() === 'seven'
            && filled(SystemSetting::readSecret('sms_seven_api_key'));
    }

    public function enabled(): bool
    {
        $integrations = (array) SystemSetting::read('integrations', []);

        return (bool) ($integrations['sms'] ?? false);
    }

    public function provider(): string
    {
        return (string) SystemSetting::read('sms_provider', 'seven');
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/webhooks/seven/sms';
    }

    /** @return array<string, mixed> */
    public function send(string $recipient, string $message): array
    {
        $this->ensureSevenConfigured();
        $sender = trim((string) SystemSetting::read('sms_sender', 'LOOKDO'));
        $response = $this->sevenRequest()->asForm()->post('https://gateway.seven.io/api/sms', [
            'to' => $recipient,
            'text' => $message,
            'from' => $sender,
        ]);
        $response->throw();
        $payload = $response->json();
        $providerMessage = $payload['messages'][0] ?? null;
        if ((string) ($payload['success'] ?? '') !== '100' || ! is_array($providerMessage) || ! ($providerMessage['success'] ?? false)) {
            throw new RuntimeException((string) ($providerMessage['error_text'] ?? $payload['debug'] ?? 'seven.io rejected the SMS.'));
        }

        return $payload;
    }

    /** @return array{amount: float, currency: string} */
    public function balance(): array
    {
        $this->ensureSevenConfigured();
        $response = $this->sevenRequest()->get('https://gateway.seven.io/api/balance');
        $response->throw();
        $payload = $response->json();

        return ['amount' => (float) ($payload['amount'] ?? 0), 'currency' => strtoupper((string) ($payload['currency'] ?? 'EUR'))];
    }

    public function validWebhook(Request $request): bool
    {
        if ($this->provider() !== 'seven') {
            return false;
        }
        $secret = (string) SystemSetting::readSecret('sms_seven_signing_key', '');
        $signature = (string) $request->header('X-Signature', '');
        $nonce = (string) $request->header('X-Nonce', '');
        $timestamp = (int) $request->header('X-Timestamp', 0);
        if ($secret === '' || $signature === '' || $nonce === '' || $timestamp === 0 || abs(time() - $timestamp) > 30) {
            return false;
        }

        $stringToSign = implode("\n", [$timestamp, $nonce, strtoupper($request->method()), $this->webhookUrl(), md5($request->getContent())]);
        $expected = hash_hmac('sha256', $stringToSign, $secret);
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        return Cache::add('seven-webhook-nonce:'.hash('sha256', $nonce), true, 60);
    }

    private function ensureSevenConfigured(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('SMS integration is disabled.');
        }
        if ($this->provider() !== 'seven') {
            throw new RuntimeException('The configured SMS provider is not supported.');
        }
        if (! filled(SystemSetting::readSecret('sms_seven_api_key'))) {
            throw new RuntimeException('The seven.io API key is missing.');
        }
    }

    private function sevenRequest(): PendingRequest
    {
        return Http::timeout(20)->acceptJson()->withHeaders([
            'X-Api-Key' => (string) SystemSetting::readSecret('sms_seven_api_key'),
        ]);
    }
}

<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PleskApiClient
{
    public function configured(): bool
    {
        return filled(config('services.plesk.api_url')) && filled(config('services.plesk.api_key'));
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    public function call(string $utility, array $params): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Plesk API is not configured.');
        }

        $response = $this->request()->post(
            rtrim((string) config('services.plesk.api_url'), '/').'/cli/'.$utility.'/call',
            ['params' => array_values($params)],
        );

        if (! $response->successful()) {
            throw new RuntimeException('Plesk API request failed: '.$response->status().' '.$response->body());
        }

        $result = $response->json();
        if (! is_array($result)) {
            throw new RuntimeException('Plesk API returned an invalid response.');
        }

        $code = (int) ($result['code'] ?? -1);
        $stderr = trim((string) ($result['stderr'] ?? ''));
        if ($code !== 0) {
            throw new RuntimeException($stderr !== '' ? $stderr : 'Plesk CLI command failed with code '.$code.'.');
        }

        return [
            'code' => $code,
            'stdout' => trim((string) ($result['stdout'] ?? '')),
            'stderr' => $stderr,
        ];
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->withHeaders(['X-API-Key' => (string) config('services.plesk.api_key')])
            ->connectTimeout(10)
            ->timeout(120)
            ->retry(2, 500, throw: false);

        return filter_var(config('services.plesk.verify_ssl', true), FILTER_VALIDATE_BOOL)
            ? $request
            : $request->withoutVerifying();
    }
}

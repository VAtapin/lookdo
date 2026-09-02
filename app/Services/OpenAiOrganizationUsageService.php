<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OpenAiOrganizationUsageService
{
    private const API_URL = 'https://api.openai.com/v1/organization';

    public function configured(): bool
    {
        return filled(SystemSetting::readSecret('openai_admin_key'));
    }

    public function summary(bool $refresh = false): array
    {
        if (! $this->configured()) {
            return [
                'configured' => false,
                'status' => 'not_configured',
                'dashboard_url' => 'https://platform.openai.com/usage',
            ];
        }

        $projectId = trim((string) SystemSetting::read('openai_project_id', ''));
        $cacheKey = 'openai:organization-usage:'.sha1($projectId ?: 'all-projects');
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        try {
            return Cache::remember($cacheKey, now()->addMinutes(5), fn (): array => $this->fetch($projectId));
        } catch (Throwable $exception) {
            report($exception);

            return [
                'configured' => true,
                'status' => 'error',
                'project_id' => $projectId ?: null,
                'error' => $this->friendlyError($exception),
                'dashboard_url' => 'https://platform.openai.com/usage',
            ];
        }
    }

    private function fetch(string $projectId): array
    {
        $start = now()->startOfMonth()->timestamp;
        $end = now()->addSecond()->timestamp;
        $common = [
            'start_time' => $start,
            'end_time' => $end,
            'bucket_width' => '1d',
            'limit' => 31,
        ];
        if ($projectId !== '') {
            $common['project_ids'] = [$projectId];
        }

        $costs = $this->request('/costs', $common + ['group_by' => ['line_item']]);
        $completions = $this->request('/usage/completions', $common);
        $images = $this->request('/usage/images', $common);

        $cost = 0.0;
        $currency = 'usd';
        $lineItems = [];
        foreach ($this->results($costs) as $result) {
            $value = (float) data_get($result, 'amount.value', 0);
            $cost += $value;
            $currency = (string) data_get($result, 'amount.currency', $currency);
            $lineItem = trim((string) ($result['line_item'] ?? '')) ?: 'Sonstige';
            $lineItems[$lineItem] = ($lineItems[$lineItem] ?? 0) + $value;
        }

        $usage = ['requests' => 0, 'input_tokens' => 0, 'cached_tokens' => 0, 'output_tokens' => 0, 'images' => 0];
        foreach ($this->results($completions) as $result) {
            $usage['requests'] += (int) ($result['num_model_requests'] ?? 0);
            $usage['input_tokens'] += (int) ($result['input_tokens'] ?? 0);
            $usage['cached_tokens'] += (int) ($result['input_cached_tokens'] ?? 0);
            $usage['output_tokens'] += (int) ($result['output_tokens'] ?? 0);
        }
        foreach ($this->results($images) as $result) {
            $usage['requests'] += (int) ($result['num_model_requests'] ?? 0);
            $usage['images'] += (int) ($result['images'] ?? 0);
        }

        arsort($lineItems);

        return [
            'configured' => true,
            'status' => 'connected',
            'project_id' => $projectId ?: null,
            'month_cost' => round($cost, 6),
            'currency' => strtolower($currency),
            'requests' => $usage['requests'],
            'input_tokens' => $usage['input_tokens'],
            'cached_tokens' => $usage['cached_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'images' => $usage['images'],
            'line_items' => collect($lineItems)->map(fn (float $value, string $name): array => [
                'name' => $name,
                'cost' => round($value, 6),
            ])->values()->all(),
            'synced_at' => now()->toIso8601String(),
            'dashboard_url' => 'https://platform.openai.com/usage',
        ];
    }

    private function request(string $path, array $query): array
    {
        $key = (string) SystemSetting::readSecret('openai_admin_key');
        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout(20)
            ->get(self::API_URL.$path, $query);

        if (! $response->successful()) {
            $this->throwForResponse($response);
        }

        return $response->json() ?? [];
    }

    private function results(array $page): array
    {
        return collect($page['data'] ?? [])->flatMap(fn (array $bucket): array => $bucket['results'] ?? [])->all();
    }

    private function throwForResponse(Response $response): never
    {
        $message = match ($response->status()) {
            401 => 'OpenAI Admin Key wurde abgelehnt.',
            403 => 'Der OpenAI-Schlüssel hat keine Organisationsrechte. Es wird ein Admin Key des Organization Owners benötigt.',
            429 => 'OpenAI begrenzt die Abfragen vorübergehend. Bitte später erneut synchronisieren.',
            default => 'OpenAI Usage API antwortet mit HTTP '.$response->status().'.',
        };

        throw new RuntimeException($message);
    }

    private function friendlyError(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException) {
            return $exception->getMessage();
        }

        return 'OpenAI Usage API ist momentan nicht erreichbar.';
    }
}

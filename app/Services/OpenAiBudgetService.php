<?php

namespace App\Services;

use App\Models\AiUsageRecord;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAiBudgetService
{
    public function ensureAvailable(?int $userId = null): void
    {
        $monthly = (float) AiUsageRecord::where('created_at', '>=', now()->startOfMonth())->sum('cost');
        if ($monthly >= (float) config('services.openai.monthly_budget')) {
            throw new RuntimeException('The monthly AI budget has been reached.');
        }

        if ($userId && AiUsageRecord::where('user_id', $userId)->whereDate('usage_date', today())->count() >= (int) config('services.openai.user_daily_limit')) {
            throw new RuntimeException('The daily AI request limit has been reached.');
        }
    }

    public function record(string $operation, string $model, int $inputTokens, int $outputTokens, ?int $userId = null, ?int $classificationId = null, ?int $tenantId = null): AiUsageRecord
    {
        $cost = ($inputTokens / 1_000_000 * (float) config('services.openai.text_input_cost_per_million'))
            + ($outputTokens / 1_000_000 * (float) config('services.openai.text_output_cost_per_million'));

        return $this->createUsageRecord([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'business_classification_id' => $classificationId,
            'operation' => $operation,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $cost,
            'usage_date' => today(),
        ]);
    }

    public function recordImage(string $operation, string $model, string $quality = 'medium', ?int $userId = null, ?int $tenantId = null): AiUsageRecord
    {
        $cost = (float) config('services.openai.image_cost_'.$quality, config('services.openai.image_cost_medium'));

        return $this->createUsageRecord([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'operation' => $operation,
            'model' => $model,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => $cost,
            'usage_date' => today(),
        ]);
    }

    public function recordTranscription(string $operation, string $model, int $inputTokens, int $outputTokens): AiUsageRecord
    {
        $cost = ($inputTokens / 1_000_000 * (float) config('services.openai.transcription_input_cost_per_million'))
            + ($outputTokens / 1_000_000 * (float) config('services.openai.transcription_output_cost_per_million'));

        return $this->createUsageRecord([
            'operation' => $operation,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $cost,
            'usage_date' => today(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createUsageRecord(array $attributes): AiUsageRecord
    {
        $tenantId = $attributes['tenant_id'] ?? null;
        if ($tenantId !== null && ! Tenant::query()->whereKey($tenantId)->exists()) {
            Log::warning('AI usage was recorded without a deleted tenant.', [
                'tenant_id' => $tenantId,
                'operation' => $attributes['operation'] ?? null,
            ]);
            $attributes['tenant_id'] = null;
        }

        try {
            return AiUsageRecord::create($attributes);
        } catch (QueryException $exception) {
            // A tenant can be removed while a long-running AI request is in flight.
            // Preserve the platform cost record instead of failing the customer request.
            if ($tenantId === null || Tenant::query()->whereKey($tenantId)->exists()) {
                throw $exception;
            }

            Log::warning('AI usage was recorded without a deleted tenant.', [
                'tenant_id' => $tenantId,
                'operation' => $attributes['operation'] ?? null,
            ]);
            $attributes['tenant_id'] = null;

            return AiUsageRecord::create($attributes);
        }
    }
}

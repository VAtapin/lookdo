<?php

namespace App\Services;

use App\Models\AiUsageRecord;
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

        return AiUsageRecord::create([
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

        return AiUsageRecord::create([
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

        return AiUsageRecord::create([
            'operation' => $operation,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $cost,
            'usage_date' => today(),
        ]);
    }
}

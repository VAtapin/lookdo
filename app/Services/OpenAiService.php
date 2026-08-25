<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiService
{
    public function configured(): bool
    {
        return filled(config('services.openai.key'));
    }

    /** @return array{text:string,model:string,input_tokens:int,output_tokens:int} */
    public function text(string $instructions, string $input): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $response = Http::withToken(config('services.openai.key'))
            ->timeout((int) config('services.openai.timeout'))
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.openai.text_model'),
                'instructions' => $instructions,
                'input' => $input,
                'text' => ['format' => ['type' => 'json_object']],
            ]);

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('error.message') ?: 'OpenAI request failed.'));
        }

        $text = $response->json('output_text');
        if (! is_string($text)) {
            $text = collect($response->json('output', []))
                ->flatMap(fn (array $item) => $item['content'] ?? [])
                ->first(fn (array $item) => isset($item['text']))['text'] ?? null;
        }
        if (! is_string($text) || $text === '') {
            throw new RuntimeException('OpenAI returned no text result.');
        }

        return [
            'text' => $text,
            'model' => (string) ($response->json('model') ?: config('services.openai.text_model')),
            'input_tokens' => (int) $response->json('usage.input_tokens', 0),
            'output_tokens' => (int) $response->json('usage.output_tokens', 0),
        ];
    }
}

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
        return $this->request($instructions, $input, ['type' => 'json_object']);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{text:string,model:string,input_tokens:int,output_tokens:int}
     */
    public function structured(string $instructions, string $input, string $name, array $schema): array
    {
        return $this->request($instructions, $input, [
            'type' => 'json_schema',
            'name' => $name,
            'strict' => true,
            'schema' => $schema,
        ]);
    }

    /** @return array{contents:string,model:string,format:string,quality:string} */
    public function image(string $prompt, string $quality = 'medium'): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $response = Http::withToken(config('services.openai.key'))
            ->timeout((int) config('services.openai.timeout'))
            ->post('https://api.openai.com/v1/images/generations', [
                'model' => config('services.openai.image_model'),
                'prompt' => $prompt,
                'size' => '1536x1024',
                'quality' => $quality,
                'output_format' => 'webp',
                'n' => 1,
            ]);

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('error.message') ?: 'OpenAI image generation failed.'));
        }

        $encoded = $response->json('data.0.b64_json');
        $contents = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('OpenAI returned no image result.');
        }

        return ['contents' => $contents, 'model' => (string) config('services.openai.image_model'), 'format' => 'webp', 'quality' => $quality];
    }

    /**
     * @param  array<string, mixed>  $format
     * @return array{text:string,model:string,input_tokens:int,output_tokens:int}
     */
    private function request(string $instructions, string $input, array $format): array
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
                'text' => ['format' => $format],
                'store' => false,
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

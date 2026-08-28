<?php

namespace Tests\Unit;

use App\Services\OpenAiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.key' => 'test-key',
            'services.openai.text_model' => 'gpt-5.6-luna',
            'services.openai.timeout' => 30,
        ]);
    }

    public function test_plain_text_request_does_not_send_a_structured_text_format(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'output_text' => 'Готовый ответ мастера',
            'model' => 'gpt-5.6-luna',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
        ])]);

        $result = app(OpenAiService::class)->text('Отвечайте по-русски.', 'Нужен ответ клиенту.');

        $this->assertSame('Готовый ответ мастера', $result['text']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && $request['store'] === false
            && ! array_key_exists('text', $request->data())
        );
    }

    public function test_structured_request_keeps_the_json_schema_format(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'output_text' => '{"answer":"ok"}',
            'model' => 'gpt-5.6-luna',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
        ])]);

        app(OpenAiService::class)->structured('Return JSON.', 'Input', 'answer', [
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'required' => ['answer'],
            'additionalProperties' => false,
        ]);

        Http::assertSent(fn (Request $request): bool => $request['text']['format']['type'] === 'json_schema'
            && $request['text']['format']['name'] === 'answer'
        );
    }
}

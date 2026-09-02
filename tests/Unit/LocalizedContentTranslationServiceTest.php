<?php

namespace Tests\Unit;

use App\Services\LocalizedContentTranslationService;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use Mockery;
use Tests\TestCase;

class LocalizedContentTranslationServiceTest extends TestCase
{
    public function test_it_translates_service_content_into_every_enabled_target_language(): void
    {
        $openAi = Mockery::mock(OpenAiService::class);
        $budget = Mockery::mock(OpenAiBudgetService::class);
        $budget->shouldReceive('ensureAvailable')->once()->with(12);
        $budget->shouldReceive('record')->once()->with('service_translation', 'test-model', 30, 18, 12, null, 7);
        $openAi->shouldReceive('structured')->once()->andReturn([
            'text' => json_encode([
                'name' => ['de' => 'Beinformkorrektur', 'ru' => 'Коррекция формы ноги'],
                'description' => ['de' => 'Beschreibung', 'ru' => 'Описание'],
            ], JSON_UNESCAPED_UNICODE),
            'model' => 'test-model',
            'input_tokens' => 30,
            'output_tokens' => 18,
        ]);

        $result = (new LocalizedContentTranslationService($openAi, $budget))->translateService(
            ['uk' => 'Корекція форми ноги', 'de' => 'Alter Wert'],
            ['uk' => 'Опис послуги'],
            'uk',
            ['uk', 'de', 'ru'],
            12,
            7,
        );

        $this->assertSame('Корекція форми ноги', $result['name']['uk']);
        $this->assertSame('Beinformkorrektur', $result['name']['de']);
        $this->assertSame('Коррекция формы ноги', $result['name']['ru']);
        $this->assertSame('Beschreibung', $result['description']['de']);
    }
}

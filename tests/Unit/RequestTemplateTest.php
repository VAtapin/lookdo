<?php

namespace Tests\Unit;

use App\Models\RequestTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_parent_presets_and_database_overrides_in_order(): void
    {
        RequestTemplate::query()->create([
            'code' => 'services.general',
            'name' => ['de' => 'Basis'],
            'configuration' => [
                'theme' => ['primary' => '#111111', 'surface' => '#ffffff'],
                'media' => ['photos_max' => 4],
            ],
            'enabled' => true,
            'version' => 1,
            'sort_order' => 10,
        ]);
        $child = RequestTemplate::query()->create([
            'code' => 'services.specialist',
            'parent_code' => 'services.general',
            'name' => ['de' => 'Spezialist'],
            'configuration' => [
                'theme' => ['primary' => '#ff6b00'],
                'media' => ['video_allowed' => true],
            ],
            'enabled' => true,
            'version' => 1,
            'sort_order' => 20,
        ]);

        $resolved = $child->resolvedConfiguration([
            'services.general' => [
                'theme' => ['primary' => '#000000', 'secondary' => '#222222'],
                'navigation' => ['home', 'action'],
            ],
            'services.specialist' => [
                'media' => ['photos_max' => 6, 'photos_min' => 1],
            ],
        ]);

        $this->assertSame('#ff6b00', data_get($resolved, 'theme.primary'));
        $this->assertSame('#222222', data_get($resolved, 'theme.secondary'));
        $this->assertSame('#ffffff', data_get($resolved, 'theme.surface'));
        $this->assertSame(['home', 'action'], $resolved['navigation']);
        $this->assertSame(6, data_get($resolved, 'media.photos_max'));
        $this->assertSame(1, data_get($resolved, 'media.photos_min'));
        $this->assertTrue(data_get($resolved, 'media.video_allowed'));
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class DomainArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_subdomain_resolves_tenant_without_exposing_unknown_hosts(): void
    {
        $tenant = Tenant::create(['name' => 'Example', 'slug' => 'example', 'country' => 'DE', 'locale' => 'de', 'status' => 'active']);
        $current = app(CurrentTenant::class);
        $middleware = new ResolveTenant($current);
        $middleware->handle(Request::create('https://example.lookdo.app/'), fn () => new Response('ok'));
        $this->assertSame($tenant->id, $current->id());
        $middleware->handle(Request::create('https://unknown.lookdo.app/'), fn () => new Response('ok'));
        $this->assertNull($current->id());
    }

    public function test_platform_icon_renders_without_a_tenant_context(): void
    {
        $this->get('/tenant-icon/192.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }
}

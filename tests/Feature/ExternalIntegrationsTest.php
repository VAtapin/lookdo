<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\RequestTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DomainService;
use App\Services\SocialPublishingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExternalIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_telegram_publication_uses_connected_account_and_generated_caption(): void
    {
        config(['services.telegram.bot_token' => 'telegram-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 321],
            ]),
        ]);
        $tenant = $this->tenant('social-api');
        $connection = $tenant->socialConnections()->create([
            'provider' => 'telegram',
            'status' => 'active',
            'external_account_id' => '-100123',
            'account_name' => 'LOOKDO channel',
            'credentials' => ['username' => 'lookdo_channel'],
        ]);
        $draft = $tenant->socialDrafts()->create([
            'format' => 'feed',
            'channel' => 'telegram',
            'locale' => 'ru',
            'caption' => 'Новая работа',
            'booking_url' => 'https://social-api.lookdo.app',
            'status' => 'ready',
        ]);

        $result = app(SocialPublishingService::class)->publish($draft, $connection);

        $this->assertSame('321', $result['id']);
        $this->assertSame('https://t.me/lookdo_channel/321', $result['url']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '-100123'
            && str_contains($request['text'], 'Новая работа')
            && str_contains($request['text'], 'https://social-api.lookdo.app')
        );
    }

    public function test_facebook_publication_uses_the_connected_page(): void
    {
        config(['services.social.meta.version' => 'v21.0']);
        Http::fake([
            'https://graph.facebook.com/v21.0/123/feed' => Http::response(['id' => '123_456']),
        ]);
        $tenant = $this->tenant('facebook-api');
        $connection = $tenant->socialConnections()->create([
            'provider' => 'facebook', 'status' => 'active', 'external_account_id' => '123',
            'account_name' => 'LOOKDO Page', 'credentials' => ['access_token' => 'page-token'],
        ]);
        $draft = $tenant->socialDrafts()->create([
            'format' => 'feed', 'channel' => 'facebook', 'locale' => 'ru',
            'caption' => 'Новая работа', 'booking_url' => 'https://facebook-api.lookdo.app', 'status' => 'ready',
        ]);

        $result = app(SocialPublishingService::class)->publish($draft, $connection);

        $this->assertSame('123_456', $result['id']);
        $this->assertSame('https://www.facebook.com/123/posts/456', $result['url']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v21.0/123/feed'
            && $request['message'] === "Новая работа\n\nhttps://facebook-api.lookdo.app"
            && $request->hasHeader('Authorization', 'Bearer page-token'));
    }

    public function test_instagram_publication_creates_and_publishes_a_media_container(): void
    {
        config(['app.url' => 'https://lookdo.app', 'services.social.meta.version' => 'v21.0']);
        Storage::fake('public');
        Http::fake([
            'https://graph.facebook.com/v21.0/ig-123/media' => Http::response(['id' => 'container-1']),
            'https://graph.facebook.com/v21.0/ig-123/media_publish' => Http::response(['id' => 'media-1']),
            'https://graph.facebook.com/v21.0/media-1*' => Http::response(['permalink' => 'https://www.instagram.com/p/example/']),
        ]);
        $tenant = $this->tenant('instagram-api');
        $imagePath = 'tenant-app/'.$tenant->id.'/social/result.jpg';
        Storage::disk('public')->put($imagePath, 'image');
        $connection = $tenant->socialConnections()->create([
            'provider' => 'instagram', 'status' => 'active', 'external_account_id' => 'ig-123',
            'account_name' => 'lookdo', 'credentials' => ['access_token' => 'page-token'],
        ]);
        $draft = $tenant->socialDrafts()->create([
            'format' => 'feed', 'channel' => 'instagram', 'locale' => 'ru', 'caption' => 'До и после',
            'booking_url' => 'https://instagram-api.lookdo.app', 'image_path' => $imagePath, 'status' => 'ready',
        ]);

        $result = app(SocialPublishingService::class)->publish($draft, $connection);

        $this->assertSame('media-1', $result['id']);
        $this->assertSame('https://www.instagram.com/p/example/', $result['url']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v21.0/ig-123/media'
            && $request['image_url'] === 'https://lookdo.app/storage/'.$imagePath);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v21.0/ig-123/media_publish'
            && $request['creation_id'] === 'container-1');
    }

    public function test_vk_publication_posts_to_the_connected_wall(): void
    {
        config(['services.social.vk.version' => '5.199']);
        Http::fake([
            'https://api.vk.com/method/wall.post' => Http::response(['response' => ['post_id' => 987]]),
        ]);
        $tenant = $this->tenant('vk-api');
        $connection = $tenant->socialConnections()->create([
            'provider' => 'vk', 'status' => 'active', 'external_account_id' => '-321',
            'account_name' => 'LOOKDO VK', 'credentials' => ['access_token' => 'vk-token'],
        ]);
        $draft = $tenant->socialDrafts()->create([
            'format' => 'feed', 'channel' => 'vk', 'locale' => 'ru',
            'caption' => 'Свежая работа', 'booking_url' => 'https://vk-api.lookdo.app', 'status' => 'ready',
        ]);

        $result = app(SocialPublishingService::class)->publish($draft, $connection);

        $this->assertSame('987', $result['id']);
        $this->assertSame('https://vk.com/wall-321_987', $result['url']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.vk.com/method/wall.post'
            && $request['owner_id'] === -321
            && $request['from_group'] === 1
            && $request['access_token'] === 'vk-token');
    }

    public function test_tenant_owner_can_connect_a_telegram_channel_where_the_bot_is_available(): void
    {
        config([
            'services.telegram.bot_token' => 'telegram-token',
            'services.telegram.bot_username' => 'lookdo_bot',
            'services.telegram.webhook_secret' => 'webhook-secret',
        ]);
        Http::fake([
            'https://api.telegram.org/*/getChat' => Http::response([
                'ok' => true,
                'result' => ['id' => -100123, 'title' => 'LOOKDO channel', 'username' => 'lookdo_channel'],
            ]),
        ]);
        $tenant = $this->tenant('telegram-connect');
        $owner = User::factory()->create(['is_active' => true]);
        $tenant->users()->attach($owner->id, ['role' => 'owner']);
        $tenant->subscriptions()->create([
            'plan_id' => Plan::where('code', 'business')->firstOrFail()->id,
            'provider' => 'lookdo',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'currency' => 'EUR',
            'started_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->actingAs($owner)
            ->postJson('/api/tenant/'.$tenant->id.'/social-connections/telegram/authorize', [
                'target' => '@lookdo_channel',
            ])
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('connection.account_name', 'LOOKDO channel');

        $this->assertDatabaseHas('tenant_social_connections', [
            'tenant_id' => $tenant->id,
            'provider' => 'telegram',
            'external_account_id' => '-100123',
            'status' => 'active',
        ]);
    }

    public function test_plesk_provisions_domain_alias_and_issues_certificate(): void
    {
        config([
            'app.url' => 'http://localhost',
            'services.plesk.api_url' => 'https://plesk.example.test:8443/api/v2',
            'services.plesk.api_key' => 'plesk-key',
            'services.plesk.subscription_domain' => 'lookdo.app',
            'services.plesk.letsencrypt_email' => 'support@lookdo.app',
        ]);
        Http::fakeSequence()
            ->push(['code' => 0, 'stdout' => 'Domain alias created.', 'stderr' => ''])
            ->push(['code' => 0, 'stdout' => 'Certificate installed.', 'stderr' => '']);
        $tenant = $this->tenant('plesk-api');
        $platform = $tenant->domains()->create([
            'domain' => 'plesk-api.lookdo.app',
            'type' => 'platform',
            'status' => 'active',
            'provisioning_status' => 'provisioned',
            'ssl_status' => 'active',
            'is_primary' => true,
        ]);
        $tenant->update(['primary_domain_id' => $platform->id]);
        $custom = $tenant->domains()->create([
            'domain' => 'localhost',
            'type' => 'custom',
            'status' => 'pending',
            'provisioning_status' => 'pending',
            'ssl_status' => 'pending',
            'is_primary' => false,
        ]);

        $verified = app(DomainService::class)->verify($custom);

        $this->assertSame('active', $verified->status);
        $this->assertSame('active', $verified->ssl_status);
        $this->assertTrue($verified->is_primary);
        $this->assertSame($verified->id, $tenant->fresh()->primary_domain_id);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://plesk.example.test:8443/api/v2/cli/domalias/call'
                && $request->hasHeader('X-API-Key', 'plesk-key')
                && $request['params'][0] === '--create'
                && in_array('localhost', $request['params'], true);
        });
        Http::assertSent(fn (Request $request) => $request->url() === 'https://plesk.example.test:8443/api/v2/cli/extension/call'
            && in_array('letsencrypt', $request['params'], true)
            && in_array('support@lookdo.app', $request['params'], true)
        );
    }

    private function tenant(string $slug): Tenant
    {
        $template = RequestTemplate::where('code', 'automotive.steering-wheel-upholstery')->firstOrFail();
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'active',
            'country' => 'DE',
            'locale' => 'ru',
        ]);
        $tenant->businessProfile()->create([
            'category_id' => $template->category_id,
            'variation_id' => $template->variation_id,
            'request_template_id' => $template->id,
            'original_description' => 'Перетяжка автомобильных рулей',
        ]);

        return $tenant;
    }
}

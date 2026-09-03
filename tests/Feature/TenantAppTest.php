<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeTenantRequestMedia;
use App\Jobs\SendPlatformAdminNewRequestNotification;
use App\Jobs\SendSmsMessage;
use App\Jobs\SendTenantMasterPush;
use App\Jobs\SendTenantMessagePush;
use App\Mail\TenantNotificationMail;
use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\RequestTemplate;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\TenantAppointment;
use App\Models\TenantMessage;
use App\Models\User;
use App\Services\BookPurchasePricingService;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use App\Services\TenantPresetService;
use App\Services\TenantWebPushService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_tenant_app_core_migration_resumes_after_partial_mysql_ddl(): void
    {
        Schema::drop('tenant_push_subscriptions');
        Schema::drop('tenant_appointments');

        $migration = require database_path('migrations/2026_08_27_000001_create_tenant_app_core.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('tenant_appointments'));
        $this->assertTrue(Schema::hasTable('tenant_push_subscriptions'));
    }

    public function test_master_message_dispatches_web_push_job_after_commit(): void
    {
        Bus::fake([SendTenantMessagePush::class]);
        $tenant = $this->tenant('push-message', 'automotive.steering-wheel-upholstery');
        $customer = $tenant->customers()->create(['name' => 'Иван', 'phone' => '+4915112345678', 'locale' => 'ru']);

        $message = TenantMessage::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'sender_type' => 'master',
            'body' => 'Мастер ответил на вашу заявку.',
        ]);

        Bus::assertDispatched(SendTenantMessagePush::class, fn ($job) => $job->tenantMessageId === $message->id);
    }

    public function test_customer_push_is_localized_and_does_not_expose_chat_content(): void
    {
        Bus::fake([SendTenantMessagePush::class]);
        $tenant = $this->tenant('neutral-push', 'automotive.steering-wheel-upholstery');
        $customer = $tenant->customers()->create(['name' => 'Иван', 'phone' => '+4915112345678', 'locale' => 'ru']);
        $message = TenantMessage::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'sender_type' => 'master',
            'body' => 'Личный текст и стоимость 999 евро.',
        ]);
        $push = \Mockery::mock(TenantWebPushService::class);
        $push->shouldReceive('sendToCustomer')->once()->withArgs(function ($actualCustomer, array $payload) use ($customer, $message): bool {
            return $actualCustomer->is($customer)
                && $payload['body'] === 'Вы получили новое сообщение от мастера.'
                && $payload['action'] === 'Открыть'
                && ! str_contains($payload['body'], $message->body);
        })->andReturn(['sent' => 1, 'failed' => 0, 'expired' => 0, 'skipped' => false]);

        (new SendTenantMessagePush($message->id))->handle($push);
    }

    public function test_vapid_command_checks_configuration_without_external_mutation(): void
    {
        config([
            'services.webpush.vapid_public_key' => 'BATakXB_Ej3dE4FS5yVhlrPr092QHa632IiQ2jQqDzwJbzpEs6F5shpVZvuZi63sziv0LikH8uGrD_r-JdKsObE',
            'services.webpush.vapid_private_key' => '0RgeAjVK7JQaTkviAqMQHWGTlqf3frBpC0op_Zr8vqQ',
            'services.webpush.subject' => 'mailto:support@lookdo.app',
        ]);

        $this->artisan('lookdo:webpush:keys', ['--check' => true])
            ->expectsOutput('Web Push configuration is complete.')
            ->assertSuccessful();
    }

    public function test_unpaid_tenant_app_is_not_publicly_usable(): void
    {
        $tenant = $this->tenant('unpaid-app', 'automotive.steering-wheel-upholstery', false);

        $this->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertStatus(402)
            ->assertHeader('Content-Language', 'ru')
            ->assertJsonPath('code', 'TENANT_APP_SUBSCRIPTION_INACTIVE')
            ->assertJsonPath('locale', 'ru')
            ->assertJsonPath('message', 'Тестовый период закончился или подписка не активна.');

        $this->withHeader('X-Locale', 'de')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertStatus(402)
            ->assertHeader('Content-Language', 'de')
            ->assertJsonPath('code', 'TENANT_APP_SUBSCRIPTION_INACTIVE')
            ->assertJsonPath('locale', 'de')
            ->assertJsonPath('message', 'Der Testzeitraum ist abgelaufen oder das Abonnement ist nicht aktiv.');
    }

    public function test_active_steering_template_bootstraps_as_localized_full_app(): void
    {
        $tenant = $this->tenant('golden-wheel', 'automotive.steering-wheel-upholstery');

        $this->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('tenant.locale', 'ru')
            ->assertJsonPath('template.hero.action', 'Оценить мой руль');

        $this->withHeader('X-Locale', 'ru')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('tenant.name', 'Golden Wheel')
            ->assertJsonPath('template.engine', 'request')
            ->assertJsonPath('template.layout', 'steering')
            ->assertJsonPath('template.hero.action', 'Оценить мой руль')
            ->assertJsonPath('entitlements.video', false)
            ->assertJsonCount(5, 'template.navigation')
            ->assertJsonCount(0, 'portfolio');

        $this->assertDatabaseCount('tenant_portfolio_items', 0);

        $this->getJson($this->url($tenant, '/manifest.webmanifest'))
            ->assertOk()->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Golden Wheel')->assertJsonPath('scope', '/')->assertJsonPath('display', 'standalone');
    }

    public function test_books_purchase_variant_exposes_isbn_photo_flow_from_shared_template(): void
    {
        $tenant = $this->tenant('book-buyer', 'purchase.general', true, 'purchase.books');

        $this->withHeader('X-Locale', 'ru')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('template.code', 'purchase.general')
            ->assertJsonPath('template.variation_code', 'purchase.books')
            ->assertJsonPath('template.hero.image', '/brand/purchase-books.webp')
            ->assertJsonPath('template.hero.eyebrow', 'ПОКУПКА КНИГ')
            ->assertJsonPath('template.hero.title', 'Продайте книги без долгой переписки')
            ->assertJsonPath('template.hero.action', 'Предложить книги')
            ->assertJsonPath('template.media.photos_min', 2)
            ->assertJsonPath('template.media_slots.0.key', 'book_cover')
            ->assertJsonPath('template.media_slots.0.title', 'Обложка книги')
            ->assertJsonPath('template.media_slots.1.key', 'book_isbn')
            ->assertJsonPath('template.fields.0.key', 'isbn')
            ->assertJsonPath('template.ai_assistant.accepts_media', true)
            ->assertJsonPath('template.capabilities.portfolio', false)
            ->assertJsonPath('template.capabilities.reviews', false)
            ->assertJsonPath('template.navigation', ['home', 'action', 'activity']);
    }

    public function test_legacy_ai_horizontal_logo_is_not_used_in_the_public_header(): void
    {
        $tenant = $this->tenant('legacy-book-logo', 'purchase.general', true, 'purchase.books');
        $tenant->profile->update(['content' => [
            'branding' => [
                'horizontal_logo_path' => 'tenant-app/1/branding/legacy.webp',
                'horizontal_logo_source' => 'ai',
            ],
        ]]);

        $this->withHeader('X-Locale', 'ru')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('tenant.branding.horizontal_logo', null);

        $tenant->profile->update(['content' => [
            'branding' => [
                'horizontal_logo_path' => 'tenant-app/1/branding/text-free.webp',
                'horizontal_logo_source' => 'ai',
                'horizontal_logo_version' => 2,
            ],
        ]]);

        $this->withHeader('X-Locale', 'ru')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('tenant.branding.horizontal_logo', '/storage/tenant-app/1/branding/text-free.webp');
    }

    public function test_book_photos_are_immediately_enriched_from_isbn_catalogues(): void
    {
        $tenant = $this->tenant('book-recognition', 'purchase.general', true, 'purchase.books');
        config(['services.openai.key' => 'test-key', 'services.openai.text_model' => 'gpt-5.6-luna']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-5.6-luna',
                'output_text' => json_encode([
                    'summary' => 'Старая книга', 'isbn' => '9783161484100', 'title' => '', 'author' => '', 'publisher' => '',
                    'publication_year' => '', 'edition' => '', 'pages' => '', 'dimensions' => '', 'illustrator' => '', 'language' => '',
                    'book_count' => '1', 'condition' => 'Потёртый переплёт', 'special_features' => '', 'listing_description' => '',
                    'asking_price' => '', 'pickup_location' => '', 'condition_grade' => 'fair', 'master_comment' => 'Проверить комплектность.',
                    'recommended_purchase_price' => 2.0, 'price_currency' => 'EUR',
                ], JSON_UNESCAPED_UNICODE),
                'usage' => ['input_tokens' => 220, 'output_tokens' => 90],
            ]),
            'openlibrary.org/*' => Http::response(['docs' => [[
                'key' => '/works/OL123W', 'title' => 'Каталожное название', 'author_name' => ['Автор Каталога'],
                'publisher' => ['Издательство'], 'publish_date' => ['1999'], 'number_of_pages_median' => 320,
            ]]]),
            'www.googleapis.com/books/*' => Http::response(['items' => [[
                'volumeInfo' => ['title' => 'Каталожное название', 'authors' => ['Автор Каталога'], 'publisher' => 'Издательство', 'publishedDate' => '1999', 'pageCount' => 320, 'language' => 'ru', 'description' => 'Описание из каталога'],
                'saleInfo' => ['retailPrice' => ['amount' => 20, 'currencyCode' => 'EUR']],
            ]]]),
        ]);

        $this->withHeader('X-Locale', 'ru')->post($this->url($tenant, '/api/tenant-app/request-assistance'), [
            'text' => '',
            'media_slots' => json_encode(['book_cover', 'book_isbn']),
            'isbn_absent' => false,
            'media' => [
                UploadedFile::fake()->image('cover.jpg', 900, 1200),
                UploadedFile::fake()->image('isbn.jpg', 1200, 900),
            ],
        ])->assertOk()
            ->assertJsonPath('book.isbn_status', 'found')
            ->assertJsonPath('values.isbn', '9783161484100')
            ->assertJsonPath('values.title', 'Каталожное название')
            ->assertJsonPath('values.author', 'Автор Каталога')
            ->assertJsonPath('values.condition', 'Потёртый переплёт')
            ->assertJsonPath('book.recommended_purchase_price', '1.60 EUR');
    }

    public function test_old_book_without_isbn_only_requires_cover_photo(): void
    {
        Storage::fake('public');
        Bus::fake([AnalyzeTenantRequestMedia::class]);
        $tenant = $this->tenant('book-without-isbn', 'purchase.general', true, 'purchase.books');

        $this->post($this->url($tenant, '/api/tenant-app/requests'), [
            'phone' => '+4915112345678',
            'summary' => 'Старинная книга без ISBN',
            'isbn_absent' => true,
            'fields' => json_encode(['condition' => 'Потёртый переплёт']),
            'media_slots' => json_encode(['book_cover']),
            'media' => [UploadedFile::fake()->image('cover.jpg', 900, 1200)],
        ])->assertCreated();

        $requestId = $tenant->appRequests()->latest('id')->value('id');
        $this->assertDatabaseHas('tenant_request_values', ['request_id' => $requestId, 'field_key' => 'isbn_absent']);
    }

    public function test_template_change_does_not_merge_stale_ai_configuration_from_previous_activity(): void
    {
        $tenant = $this->tenant('changed-to-books', 'purchase.general', true, 'purchase.books');
        $tenant->profile()->update(['content' => [
            'ai_customization' => ['status' => 'ready', 'base_template' => 'automotive.steering-wheel-upholstery'],
            'app_configuration' => [
                'hero' => ['title' => ['ru' => 'Сфотографируйте руль']],
                'media' => ['slots' => [['key' => 'wheel_front', 'title' => ['ru' => 'Фото руля']]]],
            ],
        ]]);

        $this->withHeader('X-Locale', 'ru')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('template.hero.title', 'Предложите книги на продажу')
            ->assertJsonPath('template.media_slots.0.key', 'book_cover')
            ->assertJsonCount(4, 'template.media_slots');
    }

    public function test_anonymous_customer_can_send_media_request_and_continue_on_same_device(): void
    {
        Storage::fake('public');
        Bus::fake([AnalyzeTenantRequestMedia::class]);
        $tenant = $this->tenant('wheel-request', 'automotive.steering-wheel-upholstery');

        $response = $this->withHeaders(['X-Locale' => 'ru', 'Accept' => 'application/json'])->post($this->url($tenant, '/api/tenant-app/requests'), [
            'name' => 'Иван', 'phone' => '+49 151 12345678', 'summary' => 'Потрескалась кожа сверху',
            'fields' => json_encode(['vehicle_brand' => 'BMW', 'vehicle_model' => 'X5']),
            'media_slots' => json_encode(['overall']),
            'media' => [UploadedFile::fake()->image('wheel.jpg', 1200, 900)],
        ])->assertCreated()->assertJsonPath('request.status', 'new');

        $token = $response->json('token');
        $requestId = $response->json('request.id');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('tenant_requests', ['id' => $requestId, 'tenant_id' => $tenant->id, 'locale' => 'ru']);
        $this->assertDatabaseHas('tenant_request_values', ['request_id' => $requestId, 'field_key' => 'vehicle_brand']);
        $path = $response->json('request.media.0.url');
        $this->assertStringContainsString('/storage/tenant-app/', $path);
        Bus::assertDispatched(AnalyzeTenantRequestMedia::class, fn ($job) => $job->tenantRequestId === $requestId);

        $this->withHeader('X-Lookdo-Client-Token', $token)->getJson($this->url($tenant, '/api/tenant-app/activity'))
            ->assertOk()->assertJsonPath('requests.0.id', $requestId)->assertJsonCount(1, 'requests.0.messages');

        $this->withHeader('X-Lookdo-Client-Token', $token)->postJson($this->url($tenant, "/api/tenant-app/requests/$requestId/messages"), ['body' => 'Можно связаться в WhatsApp'])
            ->assertCreated()->assertJsonPath('message.sender', 'customer');
        $this->assertDatabaseHas('tenant_messages', ['request_id' => $requestId, 'sender_type' => 'customer']);

        $endpoint = 'https://push.example.test/subscription/abc';
        $this->withHeader('X-Lookdo-Client-Token', $token)->postJson($this->url($tenant, '/api/tenant-app/push-subscriptions'), [
            'endpoint' => $endpoint, 'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-key'],
        ])->assertOk()->assertJsonPath('subscribed', true);
        $this->assertDatabaseHas('tenant_push_subscriptions', ['tenant_id' => $tenant->id, 'endpoint_hash' => hash('sha256', $endpoint)]);
    }

    public function test_media_analysis_saves_activity_specific_ai_comment_for_master(): void
    {
        Storage::fake('public');
        $tenant = $this->tenant('book-condition', 'purchase.general', true, 'purchase.books');
        $customer = $tenant->customers()->create(['name' => 'Анна', 'phone' => '+4915112345678', 'locale' => 'ru']);
        $tenantRequest = $tenant->appRequests()->create([
            'customer_id' => $customer->id,
            'request_template_id' => $tenant->businessProfile->request_template_id,
            'number' => 'R-BOOK-001',
            'status' => 'new',
            'summary' => 'Продать старую книгу',
            'locale' => 'ru',
            'contact_snapshot' => ['phone' => $customer->phone, 'business_variation_code' => 'purchase.books'],
        ]);
        Storage::disk('public')->put('tenant-app/test/book.jpg', UploadedFile::fake()->image('book.jpg')->getContent());
        $tenantRequest->media()->create(['tenant_id' => $tenant->id, 'type' => 'image', 'role' => 'condition', 'slot_key' => 'book_cover', 'sort_order' => 0, 'storage_key' => 'tenant-app/test/book.jpg', 'metadata' => ['mime' => 'image/jpeg']]);
        config(['services.openai.key' => 'test-key', 'services.openai.text_model' => 'gpt-5.6-luna']);
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-5.6-luna',
            'output_text' => '{"comment":"Внутренняя рекомендация по покупке, не оценка и не гарантия. Издание идентифицируется по каталожной записи Google Books. Переплёт заметно потёрт; корешок виден не полностью. Нужны фото ISBN и страниц с дефектами.","condition_grade":"fair","recommended_purchase_price":"2.50 EUR","price_basis":"видимый износ переплёта"}',
            'usage' => ['input_tokens' => 200, 'output_tokens' => 35],
        ])]);

        app(AnalyzeTenantRequestMedia::class, ['tenantRequestId' => $tenantRequest->id])
            ->handle(app(OpenAiService::class), app(OpenAiBudgetService::class), app(BookPurchasePricingService::class));

        $this->assertDatabaseHas('tenant_request_values', ['request_id' => $tenantRequest->id, 'field_key' => 'ai_condition_assessment']);
        $this->assertDatabaseHas('ai_usage_records', ['tenant_id' => $tenant->id, 'operation' => 'tenant_media_condition_assessment']);
        $assessment = $tenantRequest->values()->where('field_key', 'ai_condition_assessment')->firstOrFail()->value;
        $this->assertSame("• Переплёт заметно потёрт; корешок виден не полностью. Нужны фото ISBN и страниц с дефектами.\n• Закупка: 2.50 EUR — видимый износ переплёта", $assessment['display_value']);
        $this->assertLessThanOrEqual(650, mb_strlen($assessment['display_value']));
    }

    public function test_device_can_subscribe_before_creating_a_request_and_is_linked_afterwards(): void
    {
        Storage::fake('public');
        $tenant = $this->tenant('push-before-request', 'automotive.steering-wheel-upholstery');
        $endpoint = 'https://push.example.test/subscription/before-request';
        $payload = ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-key']];

        $this->withHeader('X-Locale', 'ru')->postJson($this->url($tenant, '/api/tenant-app/push-subscriptions'), $payload)
            ->assertOk()->assertJsonPath('subscribed', true);
        $this->assertDatabaseHas('tenant_push_subscriptions', [
            'tenant_id' => $tenant->id,
            'endpoint_hash' => hash('sha256', $endpoint),
            'customer_id' => null,
            'locale' => 'ru',
        ]);

        $response = $this->post($this->url($tenant, '/api/tenant-app/requests'), [
            'name' => 'Иван',
            'phone' => '8 999 123-45-67',
            'summary' => 'Нужна оценка руля',
            'fields' => json_encode([]),
            'media_slots' => json_encode(['overall']),
            'media' => [UploadedFile::fake()->image('wheel.jpg', 1200, 900)],
        ])->assertCreated();

        $customer = $tenant->customers()->where('phone', '8 999 123-45-67')->firstOrFail();

        $this->withHeader('X-Lookdo-Client-Token', $response->json('token'))
            ->postJson($this->url($tenant, '/api/tenant-app/push-subscriptions'), $payload)
            ->assertOk();
        $this->assertDatabaseHas('tenant_push_subscriptions', [
            'tenant_id' => $tenant->id,
            'endpoint_hash' => hash('sha256', $endpoint),
            'customer_id' => $customer->id,
        ]);
    }

    public function test_new_request_notifies_customer_with_context_and_emails_master(): void
    {
        Storage::fake('public');
        Mail::fake();
        Bus::fake([
            AnalyzeTenantRequestMedia::class,
            SendPlatformAdminNewRequestNotification::class,
            SendSmsMessage::class,
            SendTenantMasterPush::class,
        ]);
        $tenant = $this->tenant('context-notifications', 'automotive.steering-wheel-upholstery');
        $tenant->profile()->update(['email' => 'master@example.test']);
        DB::table('tenant_entitlement_overrides')->insert([
            ['tenant_id' => $tenant->id, 'key' => 'sms_enabled', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'key' => 'sms_monthly_limit', 'value' => '50', 'created_at' => now(), 'updated_at' => now()],
        ]);
        SystemSetting::where('key', 'integrations')->update(['value' => ['stripe' => true, 'openai' => true, 'sms' => true]]);
        SystemSetting::writeSecret('sms_seven_api_key', 'test-seven-key');

        $response = $this->withHeader('X-Locale', 'ru')->post($this->url($tenant, '/api/tenant-app/requests'), [
            'name' => 'Владимир',
            'phone' => '01713517274',
            'email' => 'customer@example.test',
            'preferred_channel' => 'sms',
            'summary' => 'Книга «Мать Мария» в твёрдом переплёте, ISBN 9783689593292.',
            'fields' => json_encode(['title' => 'Мать Мария', 'isbn' => '9783689593292']),
            'media_slots' => json_encode(['overall']),
            'media' => [UploadedFile::fake()->image('book.jpg', 900, 1200)],
        ])->assertCreated();

        $requestNumber = $response->json('request.number');
        Mail::assertSent(TenantNotificationMail::class, fn (TenantNotificationMail $mail): bool => $mail->hasTo('customer@example.test')
            && str_contains($mail->subjectLine, $requestNumber)
            && str_contains($mail->bodyText, 'Мать Мария')
            && str_contains($mail->bodyText, '/activity'));
        Mail::assertSent(TenantNotificationMail::class, fn (TenantNotificationMail $mail): bool => $mail->hasTo('master@example.test')
            && str_contains($mail->bodyText, $requestNumber)
            && str_contains($mail->bodyText, 'Мать Мария'));
        Mail::assertSentCount(2);
        $this->assertDatabaseHas('sms_messages', [
            'tenant_id' => $tenant->id,
            'event_type' => 'request_received',
            'status' => 'queued',
        ]);
        $smsText = $tenant->smsMessages()->latest('id')->value('message');
        $this->assertStringContainsString($requestNumber, $smsText);
        $this->assertStringContainsString('Мать Мария', $smsText);
    }

    public function test_only_customer_with_unreviewed_completed_request_can_submit_review(): void
    {
        $tenant = $this->tenant('review-eligibility', 'automotive.steering-wheel-upholstery');
        $customer = $tenant->customers()->create(['name' => 'Иван', 'phone' => '+4915112345678', 'locale' => 'ru']);
        $token = 'review-device-token';
        $customer->tokens()->create([
            'tenant_id' => $tenant->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addYear(),
        ]);
        $tenantRequest = $tenant->appRequests()->create([
            'customer_id' => $customer->id,
            'request_template_id' => $tenant->businessProfile->request_template_id,
            'number' => 'R-REVIEW-001',
            'status' => 'in_progress',
            'summary' => 'Перетяжка руля',
            'locale' => 'ru',
        ]);
        $headers = ['X-Lookdo-Client-Token' => $token, 'X-Locale' => 'ru'];

        $this->withHeaders($headers)->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('session.review.can_submit', false);
        $this->withHeaders($headers)->postJson($this->url($tenant, '/api/tenant-app/reviews'), [
            'rating' => 5,
            'body' => 'Отличная работа',
        ])->assertForbidden()->assertJsonPath('code', 'TENANT_APP_REVIEW_REQUIRES_COMPLETED_REQUEST');

        $tenantRequest->update(['status' => 'completed', 'completed_at' => now()]);

        $this->withHeaders($headers)->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('session.review.can_submit', true)
            ->assertJsonPath('session.review.request_id', $tenantRequest->id);
        $this->withHeaders($headers)->postJson($this->url($tenant, '/api/tenant-app/reviews'), [
            'rating' => 5,
            'body' => 'Отличная работа',
        ])->assertCreated()
            ->assertJsonPath('review.request_id', $tenantRequest->id)
            ->assertJsonPath('review.author_name', 'Иван');

        $this->withHeaders($headers)->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('session.review.can_submit', false)
            ->assertJsonPath('session.review.submitted', true);
    }

    public function test_brow_template_seeds_services_and_books_only_free_slots(): void
    {
        $tenant = $this->tenant('brow-studio', 'beauty.brows');
        $actions = ['de' => 'Termin buchen', 'en' => 'Book an appointment', 'ru' => 'Записаться', 'uk' => 'Записатися'];
        foreach ($actions as $locale => $action) {
            $this->withHeader('X-Locale', $locale)->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
                ->assertOk()
                ->assertJsonPath('template.engine', 'booking')
                ->assertJsonPath('template.layout', 'brows')
                ->assertJsonPath('template.navigation', ['home', 'services', 'book', 'activity', 'reviews'])
                ->assertJsonPath('template.hero.action', $action)
                ->assertJsonPath('template.theme.primary', '#c8663e')
                ->assertJsonPath('services.0.image', '/brand/service-brows.webp');
        }

        $bootstrap = $this->withHeader('X-Locale', 'uk')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'));
        $serviceId = $bootstrap->json('services.0.id');
        $this->assertNotNull($serviceId);

        $date = CarbonImmutable::now('Europe/Berlin')->addDay();
        while ($date->dayOfWeekIso > 5) {
            $date = $date->addDay();
        }
        $availability = $this->getJson($this->url($tenant, '/api/tenant-app/availability?service_id='.$serviceId.'&date='.$date->format('Y-m-d')))
            ->assertOk();
        $startsAt = $availability->json('slots.0.starts_at');
        $this->assertNotNull($startsAt);

        $push = \Mockery::mock(TenantWebPushService::class);
        $push->shouldReceive('configured')->once()->andReturnTrue();
        $push->shouldReceive('sendToCustomer')->once()->withArgs(function ($customer, array $payload): bool {
            return $customer->phone === '+380671234567'
                && str_contains($payload['body'], 'підтверджено')
                && $payload['url'] === '/activity';
        })->andReturn(['sent' => 1, 'failed' => 0, 'expired' => 0, 'skipped' => false]);
        $this->app->instance(TenantWebPushService::class, $push);

        $booking = $this->withHeader('X-Locale', 'uk')->postJson($this->url($tenant, '/api/tenant-app/appointments'), [
            'service_id' => $serviceId, 'starts_at' => $startsAt, 'name' => 'Олена', 'phone' => '+380671234567', 'comment' => 'Перша процедура',
            'preferred_channel' => 'push',
            'push_subscription' => [
                'endpoint' => 'https://push.example.test/subscription-1',
                'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
            ],
        ])->assertCreated()->assertJsonPath('appointment.status', 'pending');
        $token = $booking->json('token');
        $appointmentId = $booking->json('appointment.id');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('tenant_push_subscriptions', [
            'tenant_id' => $tenant->id,
            'endpoint' => 'https://push.example.test/subscription-1',
            'customer_id' => TenantAppointment::findOrFail($appointmentId)->customer_id,
        ]);

        $this->postJson($this->url($tenant, '/api/tenant-app/appointments'), [
            'service_id' => $serviceId, 'starts_at' => $startsAt, 'name' => 'Марія', 'phone' => '+380671111111',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        $tenant->services()->whereKey($serviceId)->update([
            'active' => false,
            'booking_enabled' => false,
            'archived_at' => now(),
        ]);
        $rescheduleAvailability = $this
            ->withHeader('X-Lookdo-Client-Token', $token)
            ->getJson($this->url($tenant, '/api/tenant-app/availability?service_id='.$serviceId.'&appointment_id='.$appointmentId.'&date='.$date->format('Y-m-d')))
            ->assertOk();
        $rescheduledStart = $rescheduleAvailability->json('slots.1.starts_at');
        $this->assertNotNull($rescheduledStart);
        $this->withHeader('X-Lookdo-Client-Token', $token)
            ->patchJson($this->url($tenant, '/api/tenant-app/appointments/'.$appointmentId), ['starts_at' => $rescheduledStart])
            ->assertOk()
            ->assertJsonPath('appointment.status', 'confirmed')
            ->assertJsonPath('appointment.starts_at', $rescheduledStart);

        $this->withHeader('X-Lookdo-Client-Token', $token)
            ->deleteJson($this->url($tenant, '/api/tenant-app/appointments/'.$appointmentId))
            ->assertOk()
            ->assertJsonPath('appointment.status', 'cancelled');
        $this->assertDatabaseHas('tenant_appointments', ['id' => $appointmentId, 'status' => 'cancelled']);
    }

    public function test_ivanna_brows_preset_is_personalized_without_changing_the_reusable_template(): void
    {
        $generic = $this->tenant('generic-brows', 'beauty.brows');
        $ivanna = $this->tenant('ivanna-brows', 'beauty.brows');

        app(TenantPresetService::class)->apply($ivanna, 'ivanna-brows', true);

        $this->withHeader('X-Locale', 'en')->getJson($this->url($generic, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('template.code', 'beauty.brows')
            ->assertJsonCount(4, 'template.locales');

        $response = $this->withHeader('X-Locale', 'en')->getJson($this->url($ivanna, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('tenant.name', 'Ivanna Brows')
            ->assertJsonPath('tenant.locale', 'uk')
            ->assertJsonPath('tenant.contact.phone', '+49 174 4812109')
            ->assertJsonPath('tenant.branding.horizontal_logo', '/brand/tenants/ivanna-brows/logo-horizontal.webp')
            ->assertJsonPath('tenant.branding.service_modes.0', 'workshop')
            ->assertJsonPath('tenant.branding.service_modes.1', 'on_site')
            ->assertJsonPath('template.code', 'beauty.brows')
            ->assertJsonPath('template.hero.action', 'Записатися')
            ->assertJsonCount(3, 'template.locales')
            ->assertJsonCount(6, 'services')
            ->assertJsonPath('services.0.name', 'Корекція та фарбування брів')
            ->assertJsonPath('services.0.price', '20.00');

        $this->assertDatabaseHas('tenant_services', [
            'tenant_id' => $ivanna->id,
            'repeat_interval_days' => 28,
            'duration_minutes' => 50,
            'active' => true,
        ]);
        $this->assertSame(6, $ivanna->services()->where('active', true)->count());

        $serviceId = $response->json('services.0.id');
        $date = CarbonImmutable::now('Europe/Berlin')->addDay();
        while ($date->dayOfWeekIso > 5) {
            $date = $date->addDay();
        }
        $startsAt = $this->getJson($this->url($ivanna, '/api/tenant-app/availability?service_id='.$serviceId.'&date='.$date->format('Y-m-d')))
            ->assertOk()
            ->json('slots.0.starts_at');

        $this->withHeader('X-Locale', 'uk')->postJson($this->url($ivanna, '/api/tenant-app/appointments'), [
            'service_id' => $serviceId,
            'starts_at' => $startsAt,
            'name' => 'Олена',
            'phone' => '+4915112345678',
            'service_mode' => 'on_site',
        ])->assertUnprocessable()->assertJsonValidationErrors('service_address');

        $booking = $this->withHeader('X-Locale', 'uk')->postJson($this->url($ivanna, '/api/tenant-app/appointments'), [
            'service_id' => $serviceId,
            'starts_at' => $startsAt,
            'name' => 'Олена',
            'phone' => '+4915112345678',
            'service_mode' => 'on_site',
            'service_address' => 'Musterstr. 5, Templin',
        ])->assertCreated()
            ->assertJsonPath('appointment.service_mode', 'on_site')
            ->assertJsonPath('appointment.service_address', 'Musterstr. 5, Templin');

        $appointment = TenantAppointment::findOrFail($booking->json('appointment.id'));
        $this->assertSame('on_site', $appointment->contact_snapshot['service_mode']);
        $this->assertSame('Musterstr. 5, Templin', $appointment->contact_snapshot['service_address']);
    }

    public function test_leonid_preset_uses_real_work_photos_and_keeps_the_reusable_template_generic(): void
    {
        $generic = $this->tenant('generic-steering', 'automotive.steering-wheel-upholstery');
        $leonid = $this->tenant('leonid', 'automotive.steering-wheel-upholstery');
        $leonid->portfolioItems()->create([
            'title' => ['ru' => 'Старый пример'],
            'image_path' => '/brand/leonid-demo.webp',
            'published' => true,
        ]);

        $presets = app(TenantPresetService::class);
        $presets->apply($leonid, 'leonid-steering', true);
        $presets->apply($leonid->fresh(), 'leonid-steering', true);

        $this->withHeader('X-Locale', 'de')->getJson($this->url($generic, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('template.hero.image', '/brand/steering-wheel-placeholder.svg')
            ->assertJsonCount(0, 'portfolio');

        $response = $this->withHeader('X-Locale', 'ru')->getJson($this->url($leonid, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('tenant.name', 'Перетяжка рулей')
            ->assertJsonPath('tenant.locale', 'ru')
            ->assertJsonPath('tenant.logo', '/brand/tenants/leonid-steering/avtorul-app-icon.png')
            ->assertJsonPath('tenant.branding.horizontal_logo', '/brand/tenants/leonid-steering/avtorul-wide-logo.png')
            ->assertJsonPath('tenant.branding.hero_image', '/brand/tenants/leonid-steering/hero-v2.png')
            ->assertJsonPath('tenant.branding.service_modes.0', 'workshop')
            ->assertJsonPath('tenant.branding.service_modes.1', 'on_site')
            ->assertJsonPath('template.code', 'automotive.steering-wheel-upholstery')
            ->assertJsonPath('template.hero.action', 'Оценить мой руль')
            ->assertJsonPath('portfolio.0.before_image', '/brand/tenants/leonid-steering/chevrolet-before.webp')
            ->assertJsonPath('portfolio.0.after_image', '/brand/tenants/leonid-steering/chevrolet-after.webp')
            ->assertJsonCount(4, 'template.locales')
            ->assertJsonCount(2, 'services')
            ->assertJsonCount(10, 'portfolio')
            ->assertJsonCount(18, 'reviews')
            ->assertJsonPath('reviews.0.author', 'Александр')
            ->assertJsonPath('reviews.0.rating', 5)
            ->assertJsonPath('reviews.0.received_at', '2026-07-07T09:00:00+00:00');

        $this->assertDatabaseMissing('tenant_portfolio_items', [
            'tenant_id' => $leonid->id,
            'image_path' => '/brand/leonid-demo.webp',
        ]);
        $this->assertSame(10, $leonid->portfolioItems()->count());
        $this->assertSame(18, $leonid->reviews()->count());
        $this->assertSame(18, $leonid->reviews()->where('rating', 5)->count());
    }

    public function test_recent_unpaid_subscription_is_activated_as_full_trial(): void
    {
        $plan = Plan::where('code', 'start')->firstOrFail();
        $plan->update(['trial_days' => 14]);
        $tenant = $this->tenant('full-trial', 'automotive.steering-wheel-upholstery', false);

        $migration = require database_path('migrations/2026_08_27_000003_activate_existing_plan_trials.php');
        $migration->up();

        $subscription = $tenant->fresh()->currentSubscription;
        $this->assertSame('trialing', $subscription->status);
        $this->assertTrue($subscription->isTrialActive());
        $this->assertGreaterThanOrEqual(13, $subscription->trial_days_remaining);
        $this->assertTrue($tenant->fresh()->hasActiveSubscription());

        $this->withHeader('X-Locale', 'ru')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('entitlements.video', true)
            ->assertJsonPath('entitlements.booking', true);
    }

    public function test_manual_access_repairs_the_duplicate_subscription_and_opens_the_public_app(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $tenant = $this->tenant('manual-trial', 'automotive.steering-wheel-upholstery', false);
        $trial = $tenant->currentSubscription;
        $trial->update([
            'provider' => 'lookdo',
            'status' => 'trialing',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addDays(13),
        ]);
        $manual = $tenant->subscriptions()->create([
            'plan_id' => $trial->plan_id,
            'provider' => 'manual',
            'status' => 'complimentary',
            'complimentary' => true,
            'billing_cycle' => 'monthly',
            'currency' => 'EUR',
            'started_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(14),
        ]);

        $migration = require database_path('migrations/2026_08_27_000005_separate_manual_access_from_subscriptions.php');
        $migration->up();

        $tenant->refresh();
        $this->assertTrue($tenant->hasManualAccess());
        $this->assertTrue($tenant->hasActiveSubscription());
        $this->assertSame($trial->id, $tenant->currentSubscription->id);
        $this->assertSame('superseded', $manual->fresh()->status);

        $this->withHeader('X-Locale', 'ru')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk();
        $this->actingAs($admin)->getJson('/api/control/subscriptions?search=manual-trial')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $trial->id);

        $firstUntil = $tenant->manual_access_until->copy();
        $this->actingAs($admin)->postJson('/api/control/tenants/'.$tenant->id.'/grant-access', ['days' => 14])
            ->assertOk()
            ->assertJsonPath('tenant.manual_access_active', true);

        $tenant->refresh();
        $this->assertSame(2, $tenant->subscriptions()->count());
        $this->assertSame(1, $tenant->subscriptions()->where('status', '!=', 'superseded')->count());
        $this->assertTrue($tenant->manual_access_until->greaterThanOrEqualTo($firstUntil->addDays(14)));
    }

    public function test_expired_trial_no_longer_grants_application_access(): void
    {
        $tenant = $this->tenant('expired-trial', 'automotive.steering-wheel-upholstery', false);
        $tenant->currentSubscription()->update([
            'status' => 'trialing',
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->subDay(),
        ]);

        $this->assertFalse($tenant->fresh()->hasActiveSubscription());
        $this->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertStatus(402)
            ->assertHeader('Content-Language', 'ru')
            ->assertJsonPath('code', 'TENANT_APP_SUBSCRIPTION_INACTIVE')
            ->assertJsonPath('locale', 'ru')
            ->assertJsonPath('message', 'Тестовый период закончился или подписка не активна.');
    }

    private function tenant(string $slug, string $templateCode, bool $active = true, ?string $variationCode = null): Tenant
    {
        $template = RequestTemplate::where('code', $templateCode)->firstOrFail();
        $tenant = Tenant::create(['name' => $slug === 'golden-wheel' ? 'Golden Wheel' : ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug, 'status' => 'active', 'country' => 'DE', 'locale' => 'ru', 'business_description' => 'Индивидуальная работа мастера']);
        $tenant->profile()->create(['contact_name' => 'Owner', 'phone' => '+4915112345678', 'city' => 'Berlin', 'primary_color' => '#d6a552', 'secondary_color' => '#111318']);
        $variationId = $variationCode ? BusinessVariation::where('code', $variationCode)->value('id') : $template->variation_id;
        $tenant->businessProfile()->create(['category_id' => $template->category_id, 'variation_id' => $variationId, 'request_template_id' => $template->id, 'original_description' => 'Индивидуальная работа мастера']);
        $plan = Plan::where('code', 'start')->firstOrFail();
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'provider' => 'manual', 'status' => $active ? 'active' : 'incomplete', 'started_at' => now()]);

        return $tenant;
    }

    private function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->slug.'.'.config('tenancy.platform_domain').$path;
    }
}

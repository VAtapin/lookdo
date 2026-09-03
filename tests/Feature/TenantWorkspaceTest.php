<?php

namespace Tests\Feature;

use App\Jobs\SendTenantMessagePush;
use App\Models\Plan;
use App\Models\RequestTemplate;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_workspace_migration_can_resume_after_partial_ddl(): void
    {
        Schema::drop('tenant_social_drafts');

        $migration = require database_path('migrations/2026_08_28_000001_create_tenant_master_workspace.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('tenant_social_drafts'));
        $this->assertTrue(Schema::hasColumn('tenant_services', 'buffer_before_minutes'));
        $this->assertTrue(Schema::hasColumn('tenant_customers', 'possible_duplicate_of_id'));
    }

    public function test_customer_request_is_visible_and_master_reply_returns_to_same_activity(): void
    {
        Bus::fake([SendTenantMessagePush::class]);
        $tenant = $this->tenant('workspace-request');
        $owner = $this->owner($tenant);
        $customer = $tenant->customers()->create(['name' => 'Иван', 'locale' => 'ru']);
        $token = 'known-device-token';
        $customer->tokens()->create([
            'tenant_id' => $tenant->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addYear(),
        ]);
        $appRequest = $tenant->appRequests()->create([
            'customer_id' => $customer->id,
            'request_template_id' => $tenant->businessProfile->request_template_id,
            'number' => 'R-TEST-001',
            'status' => 'new',
            'summary' => 'Нужно перетянуть руль',
            'locale' => 'ru',
            'contact_snapshot' => ['name' => 'Иван', 'phone' => '+49 151 12345678', 'preferred_channel' => 'push'],
        ]);
        $appRequest->values()->create(['field_key' => 'vehicle_brand', 'value' => ['value' => 'BMW']]);
        $appRequest->messages()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'sender_type' => 'customer',
            'body' => 'Когда можно привезти?',
        ]);
        $service = $tenant->services()->firstOrFail();
        $appointment = $tenant->appointments()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'number' => 'A-TEST-001',
            'status' => 'pending',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes($service->duration_minutes),
            'locale' => 'ru',
        ]);

        $this->actingAs($owner)->getJson('/api/tenant/'.$tenant->id.'/workspace/requests')
            ->assertOk()
            ->assertJsonPath('items.data.0.id', $appRequest->id)
            ->assertJsonPath('items.data.0.messages.0.body', 'Когда можно привезти?')
            ->assertJsonPath('items.data.0.details.1.value', '+49 151 12345678')
            ->assertJsonPath('items.data.0.details.5.label', 'Марка автомобиля')
            ->assertJsonPath('items.data.0.details.5.value', 'BMW')
            ->assertJsonPath('items.data.0.details.6.label', 'Модель')
            ->assertJsonPath('items.data.0.details.6.value', null)
            ->assertJsonPath('appointments.0.id', $appointment->id)
            ->assertJsonPath('appointments.0.kind', 'appointment')
            ->assertJsonPath('appointments.0.status', 'pending');

        $this->actingAs($owner)->putJson('/api/tenant/'.$tenant->id.'/workspace/appointments/'.$appointment->id, [
            'status' => 'confirmed',
        ])->assertOk()->assertJsonPath('appointment.status', 'confirmed');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'workspace.appointment.updated',
            'actor_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'subject_id' => $appointment->id,
        ]);

        $this->actingAs($owner)->getJson('/api/tenant/'.$tenant->id.'/workspace')
            ->assertOk()
            ->assertJsonPath('counts.messages', 1);

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/workspace/requests/'.$appRequest->id.'/read')
            ->assertOk()
            ->assertJsonPath('marked', 1)
            ->assertJsonPath('unread', 0);

        $this->assertNotNull($appRequest->messages()->where('sender_type', 'customer')->firstOrFail()->read_at);

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/workspace/requests/'.$appRequest->id.'/reply', [
            'body' => 'Можно завтра в 10:00.',
            'event' => 'master_replied',
        ])->assertCreated();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'workspace.request.replied',
            'actor_id' => $owner->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->withHeader('X-Lookdo-Client-Token', $token)
            ->getJson($this->tenantUrl($tenant, '/api/tenant-app/activity'))
            ->assertOk()
            ->assertJsonPath('requests.0.messages.1.body', 'Можно завтра в 10:00.')
            ->assertJsonPath('requests.0.messages.1.sender', 'master');
    }

    public function test_ai_reply_uses_full_request_chat_assessment_and_internal_note(): void
    {
        config(['services.openai.key' => 'test-key', 'services.openai.text_model' => 'gpt-5.6-luna']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => 'Книгу можно отправить по указанному в заявке адресу. Перед отправкой, пожалуйста, надёжно упакуйте её.',
                'model' => 'gpt-5.6-luna',
                'usage' => ['input_tokens' => 420, 'output_tokens' => 35],
            ]),
        ]);
        $tenant = $this->tenant('contextual-ai-reply');
        $owner = $this->owner($tenant);
        DB::table('tenant_entitlement_overrides')->insert([
            'tenant_id' => $tenant->id,
            'key' => 'ai_communication_enabled',
            'value' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = $tenant->customers()->create(['name' => 'Владимир', 'locale' => 'ru']);
        $appRequest = $tenant->appRequests()->create([
            'customer_id' => $customer->id,
            'request_template_id' => $tenant->businessProfile->request_template_id,
            'number' => 'R-BOOK-001',
            'status' => 'viewed',
            'summary' => 'Книга в твёрдом переплёте, состояние хорошее.',
            'internal_note' => 'Старая сохранённая заметка.',
            'locale' => 'ru',
            'contact_snapshot' => ['name' => 'Владимир', 'preferred_channel' => 'sms'],
        ]);
        $appRequest->values()->create(['field_key' => 'isbn', 'value' => ['value' => '9783689593292']]);
        $appRequest->values()->create(['field_key' => 'ai_condition_assessment', 'value' => [
            'comment' => 'Переплёт и корешок целые.',
            'recommended_purchase_price' => '8 EUR',
        ]]);
        foreach ([
            ['system', 'Ваша заявка получена. Мастер изучит её и ответит здесь.'],
            ['master', 'Мы готовы купить книгу за 8 евро.'],
            ['customer', 'Хорошо, куда и как отправить?'],
        ] as [$sender, $body]) {
            $appRequest->messages()->create([
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'sender_type' => $sender,
                'body' => $body,
            ]);
        }

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/workspace/ai', [
            'task' => 'reply',
            'locale' => 'ru',
            'request_id' => $appRequest->id,
            'internal_note' => 'Отправка на склад в Берлине; адрес сообщить клиенту отдельно.',
        ])->assertOk()->assertJsonPath('text', 'Книгу можно отправить по указанному в заявке адресу. Перед отправкой, пожалуйста, надёжно упакуйте её.');

        Http::assertSent(function (HttpRequest $request): bool {
            if ($request->url() !== 'https://api.openai.com/v1/responses') {
                return false;
            }
            $input = (string) $request['input'];
            $instructions = (string) $request['instructions'];

            return str_contains($input, '9783689593292')
                && str_contains($input, 'Переплёт и корешок целые.')
                && str_contains($input, 'Мы готовы купить книгу за 8 евро.')
                && str_contains($input, 'Хорошо, куда и как отправить?')
                && str_contains($input, 'Отправка на склад в Берлине')
                && str_contains($input, 'system_notice')
                && str_contains($instructions, 'Never repeat, paraphrase or resend')
                && str_contains($instructions, 'System notices are background information');
        });
    }

    public function test_same_phone_on_unknown_device_is_only_duplicate_signal_and_never_opens_history(): void
    {
        Storage::fake('public');
        $tenant = $this->tenant('device-identity');
        $payload = [
            'name' => 'Иван',
            'phone' => '+49 151 12345678',
            'summary' => 'Первая заявка',
            'media_slots' => json_encode(['overall']),
            'media' => [UploadedFile::fake()->image('first.jpg', 900, 700)],
        ];

        $first = $this->post($this->tenantUrl($tenant, '/api/tenant-app/requests'), $payload, ['Accept' => 'application/json'])->assertCreated();
        $payload['summary'] = 'Вторая заявка';
        $payload['media'] = [UploadedFile::fake()->image('second.jpg', 900, 700)];
        $second = $this->post($this->tenantUrl($tenant, '/api/tenant-app/requests'), $payload, ['Accept' => 'application/json'])->assertCreated();

        $this->assertNotSame($first->json('token'), $second->json('token'));
        $this->assertDatabaseCount('tenant_customers', 2);
        $customers = $tenant->customers()->orderBy('id')->get();
        $this->assertSame($customers[0]->id, $customers[1]->possible_duplicate_of_id);

        $this->withHeader('X-Lookdo-Client-Token', $second->json('token'))
            ->getJson($this->tenantUrl($tenant, '/api/tenant-app/activity'))
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.id', $second->json('request.id'));
    }

    public function test_master_can_safely_merge_a_detected_duplicate_customer(): void
    {
        $tenant = $this->tenant('merge-customer');
        $owner = $this->owner($tenant);
        $primary = $tenant->customers()->create([
            'name' => 'Иван',
            'phone' => '+49 151 12345678',
            'phone_normalized' => '+4915112345678',
            'locale' => 'ru',
        ]);
        $duplicate = $tenant->customers()->create([
            'name' => 'Иван П.',
            'phone' => '+49 151 12345678',
            'phone_normalized' => '+4915112345678',
            'locale' => 'ru',
            'possible_duplicate_of_id' => $primary->id,
        ]);
        $appRequest = $tenant->appRequests()->create([
            'customer_id' => $duplicate->id,
            'number' => 'R-MERGE-001',
            'status' => 'new',
            'summary' => 'Заявка с нового устройства',
            'locale' => 'ru',
        ]);

        $this->actingAs($owner)
            ->postJson('/api/tenant/'.$tenant->id.'/workspace/customers/'.$primary->id.'/merge', [
                'source_id' => $duplicate->id,
            ])
            ->assertOk()
            ->assertJsonPath('customer.id', $primary->id);

        $this->assertDatabaseMissing('tenant_customers', ['id' => $duplicate->id]);
        $this->assertDatabaseHas('tenant_requests', [
            'id' => $appRequest->id,
            'customer_id' => $primary->id,
        ]);
    }

    public function test_calendar_creates_real_appointments_and_rejects_double_booking(): void
    {
        $tenant = $this->tenant('calendar-master');
        $owner = $this->owner($tenant);
        $customer = $tenant->customers()->create(['name' => 'Мария', 'phone' => '+491511111111', 'locale' => 'ru']);
        $service = $tenant->services()->create([
            'name' => ['de' => 'Lenkrad', 'ru' => 'Перетяжка руля'],
            'duration_minutes' => 60,
            'buffer_before_minutes' => 15,
            'buffer_after_minutes' => 15,
            'currency' => 'EUR',
            'booking_enabled' => true,
            'media_allowed' => true,
            'active' => true,
        ]);
        $date = CarbonImmutable::now('Europe/Berlin')->addDay();
        while ($date->dayOfWeekIso > 5) {
            $date = $date->addDay();
        }
        $startsAt = $date->setTime(10, 0)->toIso8601String();
        $payload = [
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'status' => 'confirmed',
            'service_mode' => 'on_site',
            'service_address' => 'Ringstr. 12, Templin',
        ];

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/calendar/appointments', $payload)
            ->assertCreated()
            ->assertJsonPath('appointment.customer.id', $customer->id)
            ->assertJsonPath('appointment.contact_snapshot.service_mode', 'on_site')
            ->assertJsonPath('appointment.contact_snapshot.service_address', 'Ringstr. 12, Templin');
        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/calendar/appointments', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
        $this->actingAs($owner)->getJson('/api/tenant/'.$tenant->id.'/calendar?from='.$date->toDateString().'&to='.$date->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'appointments')
            ->assertJsonPath('appointments.0.contact_snapshot.service_mode', 'on_site');
    }

    public function test_two_calendar_resources_can_accept_the_same_time_slot(): void
    {
        $tenant = $this->tenant('calendar-resources');
        $owner = $this->owner($tenant);
        $customer = $tenant->customers()->create(['name' => 'Мария', 'phone' => '+491511111111', 'locale' => 'ru']);
        $service = $tenant->services()->create([
            'name' => ['ru' => 'Консультация'],
            'duration_minutes' => 60,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'currency' => 'EUR',
            'booking_enabled' => true,
            'media_allowed' => true,
            'active' => true,
        ]);
        $first = $tenant->resources()->create(['name' => 'Мастер 1', 'kind' => 'staff', 'active' => true]);
        $second = $tenant->resources()->create(['name' => 'Мастер 2', 'kind' => 'staff', 'active' => true]);
        $date = CarbonImmutable::now('Europe/Berlin')->addDay();
        while ($date->dayOfWeekIso > 5) {
            $date = $date->addDay();
        }
        $payload = [
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'starts_at' => $date->setTime(10, 0)->toIso8601String(),
            'status' => 'confirmed',
        ];

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/calendar/appointments', $payload + ['resource_id' => $first->id])->assertCreated();
        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/calendar/appointments', $payload + ['resource_id' => $second->id])->assertCreated();
        $this->assertDatabaseCount('tenant_appointments', 2);
    }

    public function test_master_can_create_segments_and_assign_them_to_a_customer(): void
    {
        $tenant = $this->tenant('customer-segments');
        $owner = $this->owner($tenant);
        $customer = $tenant->customers()->create(['name' => 'Анна', 'phone' => '+491511111111', 'locale' => 'ru']);

        $segment = $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/workspace/segments', [
            'name' => 'Повторный визит',
            'color' => '#ff6b00',
            'active' => true,
        ])->assertCreated()->json('segment');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'workspace.operations.save_segment',
            'actor_id' => $owner->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($owner)->putJson('/api/tenant/'.$tenant->id.'/workspace/customers/'.$customer->id.'/segments', [
            'segment_ids' => [$segment['id']],
        ])->assertOk()->assertJsonPath('customer.segments.0.name', 'Повторный визит');

        $this->actingAs($owner)->getJson('/api/tenant/'.$tenant->id.'/workspace/segments')
            ->assertOk()
            ->assertJsonPath('segments.0.customers_count', 1);
    }

    public function test_team_limit_and_owner_protection_follow_tariff_entitlement(): void
    {
        $tenant = $this->tenant('team-limit');
        $owner = $this->owner($tenant);

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/workspace/team', [
            'name' => 'Помощник',
            'email' => 'helper@example.test',
            'role' => 'staff',
        ])->assertUnprocessable();

        DB::table('tenant_entitlement_overrides')->insert([
            'tenant_id' => $tenant->id,
            'key' => 'staff_users',
            'value' => '2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/workspace/team', [
            'name' => 'Помощник',
            'email' => 'helper@example.test',
            'role' => 'staff',
        ])->assertCreated();
        $this->assertNotEmpty($created->json('setup_url'));
        $this->actingAs($owner)->deleteJson('/api/tenant/'.$tenant->id.'/workspace/team/'.$owner->id)
            ->assertUnprocessable();
    }

    public function test_publication_requires_explicit_customer_consent_confirmation(): void
    {
        $tenant = $this->tenant('publication-consent');
        $owner = $this->owner($tenant);
        $payload = [
            'rating' => 5,
            'author_name' => 'Клиент',
            'body' => 'Отличная работа',
            'published' => true,
        ];

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/reviews', $payload)
            ->assertUnprocessable();
        $payload['publication_confirmed'] = true;
        $created = $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/reviews', $payload)
            ->assertCreated()
            ->assertJsonPath('review.published', true);

        $this->actingAs($owner)->putJson('/api/tenant/'.$tenant->id.'/reviews/'.$created->json('review.id'), $payload + [
            'master_reply' => 'Спасибо за доверие!',
        ])->assertOk()->assertJsonPath('review.master_reply', 'Спасибо за доверие!');

        $this->getJson($this->tenantUrl($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('reviews.0.master_reply', 'Спасибо за доверие!');
    }

    public function test_master_can_prepare_a_social_publication_from_own_portfolio(): void
    {
        $tenant = $this->tenant('social-publication');
        $owner = $this->owner($tenant);
        DB::table('tenant_entitlement_overrides')->insert([
            'tenant_id' => $tenant->id,
            'key' => 'social_content_enabled',
            'value' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $work = $tenant->portfolioItems()->create([
            'title' => ['ru' => 'Перетяжка руля'],
            'description' => ['ru' => 'До и после'],
            'image_path' => 'tenant-app/'.$tenant->id.'/portfolio/work.jpg',
            'published' => true,
        ]);

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/social-drafts', [
            'portfolio_item_id' => $work->id,
            'format' => 'feed',
            'channel' => 'facebook',
            'locale' => 'ru',
            'caption' => 'Готовая работа',
            'image_path' => $work->image_path,
            'booking_url' => 'https://'.$tenant->slug.'.'.config('tenancy.platform_domain'),
            'status' => 'ready',
        ])->assertCreated()
            ->assertJsonPath('draft.channel', 'facebook')
            ->assertJsonPath('draft.image_path', $work->image_path);
    }

    public function test_customer_profile_returns_only_that_customers_history(): void
    {
        $tenant = $this->tenant('customer-history');
        $owner = $this->owner($tenant);
        $first = $tenant->customers()->create(['name' => 'Иван', 'phone' => '+491511111111', 'locale' => 'ru']);
        $second = $tenant->customers()->create(['name' => 'Мария', 'phone' => '+491522222222', 'locale' => 'ru']);
        $first->requests()->create(['tenant_id' => $tenant->id, 'number' => 'R-FIRST', 'status' => 'new', 'summary' => 'Первая заявка', 'locale' => 'ru']);
        $second->requests()->create(['tenant_id' => $tenant->id, 'number' => 'R-SECOND', 'status' => 'new', 'summary' => 'Чужая заявка', 'locale' => 'ru']);

        $this->actingAs($owner)->getJson('/api/tenant/'.$tenant->id.'/workspace/customers/'.$first->id)
            ->assertOk()
            ->assertJsonPath('customer.id', $first->id)
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.number', 'R-FIRST');
    }

    public function test_sms_reminder_requires_sms_entitlement(): void
    {
        $tenant = $this->tenant('sms-entitlement');
        $owner = $this->owner($tenant);
        $customer = $tenant->customers()->create(['name' => 'Иван', 'phone' => '+491511111111', 'locale' => 'ru']);
        DB::table('tenant_entitlement_overrides')->insert([
            ['tenant_id' => $tenant->id, 'key' => 'reminders_enabled', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'key' => 'sms_enabled', 'value' => '0', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/calendar/reminders', [
            'customer_id' => $customer->id,
            'type' => 'agreement',
            'channel' => 'sms',
            'scheduled_at' => now()->addHour()->toIso8601String(),
            'message' => 'Напоминание',
        ])->assertForbidden();
    }

    public function test_master_can_subscribe_and_unsubscribe_from_neutral_push_notifications(): void
    {
        $tenant = $this->tenant('master-push');
        $owner = $this->owner($tenant);
        $endpoint = 'https://push.example.test/master-device';
        $payload = [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
        ];

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/workspace/push-subscriptions', $payload)
            ->assertOk()
            ->assertJsonPath('subscribed', true);
        $this->assertDatabaseHas('tenant_push_subscriptions', [
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'customer_id' => null,
            'endpoint_hash' => hash('sha256', $endpoint),
        ]);

        $this->actingAs($owner)->deleteJson('/api/tenant/'.$tenant->id.'/workspace/push-subscriptions', ['endpoint' => $endpoint])
            ->assertOk()
            ->assertJsonPath('subscribed', false);
        $this->assertDatabaseMissing('tenant_push_subscriptions', [
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'endpoint_hash' => hash('sha256', $endpoint),
        ]);
    }

    public function test_master_can_replace_and_remove_a_service_image(): void
    {
        Storage::fake('public');
        $tenant = $this->tenant('service-image');
        $owner = $this->owner($tenant);
        $service = $tenant->services()->create([
            'name' => ['ru' => 'Коррекция бровей', 'de' => 'Augenbrauenkorrektur'],
            'description' => [],
            'image_path' => '/brand/service-brows.webp',
            'duration_minutes' => 45,
            'price' => 35,
            'currency' => 'EUR',
            'booking_enabled' => true,
            'media_allowed' => true,
            'active' => true,
        ]);

        $response = $this->actingAs($owner)->post('/api/tenant/'.$tenant->id.'/services/'.$service->id.'/image', [
            'image' => UploadedFile::fake()->image('brows.jpg', 1400, 1000),
        ])->assertCreated();

        $path = $response->json('service.image_path');
        $this->assertStringStartsWith('tenant-app/'.$tenant->id.'/services/', $path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($owner)->deleteJson('/api/tenant/'.$tenant->id.'/services/'.$service->id.'/image')
            ->assertOk()
            ->assertJsonPath('service.image_path', null);
        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseHas('tenant_services', ['id' => $service->id, 'image_path' => null]);
    }

    private function tenant(string $slug): Tenant
    {
        $template = RequestTemplate::where('code', 'automotive.steering-wheel-upholstery')->firstOrFail();
        $tenant = Tenant::create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'status' => 'active',
            'country' => 'DE',
            'locale' => 'ru',
            'business_description' => 'Перетяжка автомобильных рулей',
        ]);
        $tenant->profile()->create(['contact_name' => 'Owner', 'phone' => '+4915112345678']);
        $tenant->businessProfile()->create([
            'category_id' => $template->category_id,
            'variation_id' => $template->variation_id,
            'request_template_id' => $template->id,
            'original_description' => 'Перетяжка автомобильных рулей',
        ]);
        $plan = Plan::where('code', 'start')->firstOrFail();
        $tenant->subscriptions()->create([
            'plan_id' => $plan->id,
            'provider' => 'lookdo',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'currency' => 'EUR',
            'started_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $tenant;
    }

    private function owner(Tenant $tenant): User
    {
        $owner = User::factory()->create(['is_active' => true]);
        $tenant->users()->attach($owner->id, ['role' => 'owner']);

        return $owner;
    }

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->slug.'.'.config('tenancy.platform_domain').$path;
    }
}

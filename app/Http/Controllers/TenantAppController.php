<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantAppointment;
use App\Models\TenantClientToken;
use App\Models\TenantCustomer;
use App\Models\TenantMessage;
use App\Models\TenantPushSubscription;
use App\Models\TenantRequest;
use App\Models\TenantRequestValue;
use App\Models\TenantService;
use App\Services\EntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantAppController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($tenant);
        $tenant->load(['profile', 'businessProfile.category', 'businessProfile.variation', 'businessProfile.template', 'currentSubscription.plan.entitlements']);
        $configuration = $this->configuration($tenant);
        $this->seedDefaults($tenant, $configuration);
        $locale = $this->locale($request, $tenant);
        $customer = $this->customerFromToken($request, $tenant);

        return response()->json([
            'tenant' => $this->tenantPayload($tenant, $locale),
            'template' => $this->templatePayload($tenant, $configuration, $locale),
            'services' => $tenant->services()->where('active', true)->orderBy('sort_order')->get()->map(fn (TenantService $service) => $this->servicePayload($service, $locale)),
            'portfolio' => $tenant->portfolioItems()->where('published', true)->orderByDesc('featured')->orderBy('sort_order')->get()->map(fn ($item) => [
                'id' => $item->id, 'title' => $item->localized('title', $locale), 'description' => $item->localized('description', $locale),
                'image' => $this->assetUrl($item->image_path), 'before_image' => $this->assetUrl($item->before_image_path), 'after_image' => $this->assetUrl($item->after_image_path), 'featured' => $item->featured,
            ]),
            'entitlements' => [
                'requests' => $this->enabled($tenant, 'request_enabled', true), 'booking' => $this->enabled($tenant, 'booking_enabled', false),
                'video' => $this->enabled($tenant, 'video_enabled', false), 'push' => $this->enabled($tenant, 'push_enabled', true),
            ],
            'push' => ['enabled' => $this->enabled($tenant, 'push_enabled', true) && filled(config('services.webpush.vapid_public_key')), 'public_key' => (string) config('services.webpush.vapid_public_key', '')],
            'session' => ['known' => (bool) $customer, 'customer' => $customer ? ['name' => $customer->name, 'phone' => $customer->phone, 'locale' => $customer->locale] : null],
        ]);
    }

    public function createRequest(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($tenant);
        abort_unless($this->enabled($tenant, 'request_enabled', true), 403, 'Requests are not included in this plan.');
        $limit = (int) $this->entitlements->get($tenant, 'requests_monthly', 0);
        if ($limit > 0 && $tenant->appRequests()->where('created_at', '>=', now()->startOfMonth())->count() >= $limit) {
            abort(429, 'The monthly request limit has been reached.');
        }

        $data = $request->validate([
            'name' => 'nullable|string|max:120', 'phone' => 'required|string|max:50', 'email' => 'nullable|email|max:190',
            'preferred_channel' => 'nullable|in:phone,whatsapp,sms,email', 'summary' => 'nullable|string|max:5000',
            'fields' => 'nullable', 'media_slots' => 'nullable', 'media' => 'required|array|min:1|max:12',
            'media.*' => 'file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime|max:262144',
        ]);
        $fields = $this->jsonArray($data['fields'] ?? []);
        $slots = $this->jsonArray($data['media_slots'] ?? []);
        $locale = $this->locale($request, $tenant);
        [$customer, $rawToken] = $this->customerAndToken($tenant, $data, $locale);

        $tenantRequest = DB::transaction(function () use ($tenant, $customer, $data, $fields, $slots, $locale, $request) {
            $appRequest = $tenant->appRequests()->create([
                'customer_id' => $customer->id,
                'request_template_id' => $tenant->businessProfile?->request_template_id,
                'number' => $this->number('R'), 'status' => 'new', 'summary' => $data['summary'] ?? null, 'locale' => $locale,
                'contact_snapshot' => Arr::only($data, ['name', 'phone', 'email', 'preferred_channel']),
            ]);
            foreach ($fields as $key => $value) {
                TenantRequestValue::create(['request_id' => $appRequest->id, 'field_key' => Str::limit((string) $key, 120, ''), 'value' => is_array($value) ? $value : ['value' => $value]]);
            }
            foreach ($request->file('media', []) as $index => $file) {
                $type = str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image';
                abort_if($type === 'video' && ! $this->enabled($tenant, 'video_enabled', false), 403, 'Video is not included in this plan.');
                $path = $file->store("tenant-app/{$tenant->id}/requests/{$appRequest->id}", 'public');
                $appRequest->media()->create(['tenant_id' => $tenant->id, 'type' => $type, 'role' => 'condition', 'slot_key' => $slots[$index] ?? null, 'sort_order' => $index, 'storage_key' => $path, 'metadata' => ['mime' => $file->getMimeType(), 'size' => $file->getSize()]]);
            }
            $appRequest->messages()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'sender_type' => 'system', 'body' => $this->message('received', $locale)]);

            return $appRequest;
        });

        return response()->json(['token' => $rawToken, 'request' => $this->requestPayload($tenantRequest->fresh(['media', 'messages'])), 'success' => $this->localized(data_get($this->configuration($tenant), 'success', []), $locale)], 201);
    }

    public function activity(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($tenant);
        $customer = $this->requireCustomer($request, $tenant);
        $requests = $customer->requests()->where('tenant_id', $tenant->id)->with(['media', 'messages'])->latest()->get()->map(fn ($item) => $this->requestPayload($item));
        $appointments = $customer->appointments()->where('tenant_id', $tenant->id)->with('service')->latest('starts_at')->get()->map(fn ($item) => $this->appointmentPayload($item, $customer->locale ?: $tenant->locale));

        return response()->json(['requests' => $requests, 'appointments' => $appointments]);
    }

    public function postMessage(Request $request, TenantRequest $tenantRequest): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($tenant);
        $customer = $this->requireCustomer($request, $tenant);
        abort_unless($tenantRequest->tenant_id === $tenant->id && $tenantRequest->customer_id === $customer->id, 404);
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $message = $tenantRequest->messages()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'sender_type' => 'customer', 'body' => $data['body']]);
        $customer->update(['last_activity_at' => now()]);

        return response()->json(['message' => $this->messagePayload($message)], 201);
    }

    public function availability(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($tenant);
        abort_unless($this->enabled($tenant, 'booking_enabled', false), 403, 'Booking is not included in this plan.');
        $data = $request->validate(['service_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d|after_or_equal:today']);
        $service = $tenant->services()->where('active', true)->findOrFail($data['service_id']);

        return response()->json(['slots' => $this->availableSlots($tenant, $service, $data['date'])]);
    }

    public function createAppointment(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($tenant);
        abort_unless($this->enabled($tenant, 'booking_enabled', false), 403, 'Booking is not included in this plan.');
        $data = $request->validate([
            'service_id' => 'required|integer', 'starts_at' => 'required|date|after:now', 'name' => 'nullable|string|max:120',
            'phone' => 'required|string|max:50', 'email' => 'nullable|email|max:190', 'comment' => 'nullable|string|max:2000',
            'preferred_channel' => 'nullable|in:phone,whatsapp,sms,email',
        ]);
        $service = $tenant->services()->where('active', true)->where('booking_enabled', true)->findOrFail($data['service_id']);
        $locale = $this->locale($request, $tenant);
        [$customer, $rawToken] = $this->customerAndToken($tenant, $data, $locale);
        $start = CarbonImmutable::parse($data['starts_at'], 'Europe/Berlin');
        $end = $start->addMinutes($service->duration_minutes);

        $appointment = DB::transaction(function () use ($tenant, $customer, $service, $data, $locale, $start, $end) {
            $overlap = TenantAppointment::where('tenant_id', $tenant->id)->whereNotIn('status', ['cancelled'])
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->lockForUpdate()->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['starts_at' => 'This time has just been booked. Please select another one.']);
            }

            return $tenant->appointments()->create([
                'customer_id' => $customer->id, 'service_id' => $service->id, 'number' => $this->number('A'), 'status' => 'pending',
                'starts_at' => $start, 'ends_at' => $end, 'comment' => $data['comment'] ?? null, 'locale' => $locale,
                'contact_snapshot' => Arr::only($data, ['name', 'phone', 'email', 'preferred_channel']),
            ]);
        });

        return response()->json(['token' => $rawToken, 'appointment' => $this->appointmentPayload($appointment->load('service'), $locale)], 201);
    }

    public function subscribePush(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($tenant);
        $customer = $this->requireCustomer($request, $tenant);
        abort_unless($this->enabled($tenant, 'push_enabled', true), 403);
        $data = $request->validate(['endpoint' => 'required|url|max:2000', 'keys.p256dh' => 'required|string|max:1000', 'keys.auth' => 'required|string|max:500']);
        $endpointHash = hash('sha256', $data['endpoint']);
        TenantPushSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id, 'endpoint_hash' => $endpointHash],
            ['customer_id' => $customer->id, 'endpoint' => $data['endpoint'], 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'], 'locale' => $customer->locale],
        );

        return response()->json(['subscribed' => true]);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function ensureAvailable(Tenant $tenant): void
    {
        abort_unless($tenant->status === 'active', 404);
        abort_unless($tenant->hasActiveSubscription(), 402, 'This application is not active yet.');
    }

    private function configuration(Tenant $tenant): array
    {
        $tenant->loadMissing('businessProfile.template');
        $configuration = (array) ($tenant->businessProfile?->template?->configuration ?? []);
        $code = $tenant->businessProfile?->template?->code ?: 'general-services.general';
        $presets = (array) config('tenant_apps.templates', []);

        return array_replace_recursive((array) ($presets[$code] ?? $presets['general-services.general'] ?? []), $configuration);
    }

    private function locale(Request $request, Tenant $tenant): string
    {
        $locale = strtolower((string) $request->header('X-Locale', $tenant->locale));

        return in_array($locale, ['de', 'en', 'ru', 'uk'], true) ? $locale : $tenant->locale;
    }

    private function enabled(Tenant $tenant, string $key, bool $default): bool
    {
        return filter_var($this->entitlements->get($tenant, $key, $default), FILTER_VALIDATE_BOOL);
    }

    private function seedDefaults(Tenant $tenant, array $configuration): void
    {
        if ($tenant->services()->doesntExist()) {
            foreach ((array) ($configuration['starter_services'] ?? []) as $index => $service) {
                $tenant->services()->create(['name' => $service['name'], 'description' => $service['description'] ?? [], 'duration_minutes' => $service['duration'] ?? 60, 'booking_enabled' => true, 'active' => true, 'sort_order' => $index * 10]);
            }
        }
        if ($tenant->portfolioItems()->doesntExist()) {
            foreach ((array) ($configuration['starter_portfolio'] ?? []) as $index => $item) {
                $tenant->portfolioItems()->create(['title' => $item['title'], 'description' => $item['description'] ?? [], 'image_path' => $item['image'] ?? null, 'featured' => $item['featured'] ?? false, 'published' => true, 'sort_order' => $index * 10]);
            }
        }
    }

    private function customerAndToken(Tenant $tenant, array $data, string $locale): array
    {
        $normalized = preg_replace('/\D+/', '', (string) $data['phone']);
        $customer = $tenant->customers()->firstOrNew(['phone_normalized' => $normalized]);
        $customer->fill(['name' => $data['name'] ?? $customer->name, 'phone' => $data['phone'], 'email' => $data['email'] ?? $customer->email, 'locale' => $locale, 'preferred_channel' => $data['preferred_channel'] ?? 'phone', 'last_activity_at' => now()])->save();
        $raw = Str::random(80);
        $customer->tokens()->create(['tenant_id' => $tenant->id, 'token_hash' => hash('sha256', $raw), 'last_used_at' => now(), 'expires_at' => now()->addYear()]);

        return [$customer, $raw];
    }

    private function customerFromToken(Request $request, Tenant $tenant): ?TenantCustomer
    {
        $raw = (string) $request->header('X-Lookdo-Client-Token');
        if ($raw === '') {
            return null;
        }
        $token = TenantClientToken::with('customer')->where('tenant_id', $tenant->id)->where('token_hash', hash('sha256', $raw))->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
        if (! $token) {
            return null;
        }
        $token->update(['last_used_at' => now()]);

        return $token->customer;
    }

    private function requireCustomer(Request $request, Tenant $tenant): TenantCustomer
    {
        return $this->customerFromToken($request, $tenant) ?? abort(401, 'This device is not linked to a request yet.');
    }

    private function availableSlots(Tenant $tenant, TenantService $service, string $date): array
    {
        $booking = (array) data_get($this->configuration($tenant), 'booking', []);
        $timezone = $booking['timezone'] ?? 'Europe/Berlin';
        $day = CarbonImmutable::parse($date, $timezone);
        if (! in_array($day->dayOfWeekIso, $booking['days'] ?? [1, 2, 3, 4, 5], true)) {
            return [];
        }
        $cursor = CarbonImmutable::parse($date.' '.($booking['start'] ?? '09:00'), $timezone);
        $close = CarbonImmutable::parse($date.' '.($booking['end'] ?? '18:00'), $timezone);
        $interval = max(15, (int) ($booking['interval'] ?? 30));
        $appointments = TenantAppointment::where('tenant_id', $tenant->id)->whereNotIn('status', ['cancelled'])->whereDate('starts_at', $date)->get(['starts_at', 'ends_at']);
        $slots = [];
        while ($cursor->addMinutes($service->duration_minutes)->lte($close)) {
            $end = $cursor->addMinutes($service->duration_minutes);
            $busy = $appointments->contains(fn ($item) => $item->starts_at->lt($end) && $item->ends_at->gt($cursor));
            if (! $busy && $cursor->isFuture()) {
                $slots[] = ['starts_at' => $cursor->toIso8601String(), 'label' => $cursor->format('H:i')];
            }
            $cursor = $cursor->addMinutes($interval);
        }

        return $slots;
    }

    private function tenantPayload(Tenant $tenant, string $locale): array
    {
        $profile = $tenant->profile;

        return [
            'id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'locale' => $locale,
            'description' => $tenant->business_description, 'logo' => $this->assetUrl($profile?->logo_path),
            'colors' => ['primary' => $profile?->primary_color ?: '#ff6b00', 'secondary' => $profile?->secondary_color ?: '#111318'],
            'contact' => ['name' => $profile?->contact_name, 'phone' => $profile?->phone, 'email' => $profile?->email, 'street' => $profile?->street, 'postal_code' => $profile?->postal_code, 'city' => $profile?->city],
        ];
    }

    private function templatePayload(Tenant $tenant, array $configuration, string $locale): array
    {
        $template = $tenant->businessProfile?->template;
        $media = (array) ($configuration['media'] ?? []);
        $slots = $this->localizedSlots((array) ($media['slots'] ?? $configuration['media_slots'] ?? []), $locale);
        $fields = $this->localizedFields((array) ($configuration['fields'] ?? []), $locale);

        return [
            'id' => $template?->id, 'code' => $template?->code ?: 'general-services.general', 'name' => $template?->localized('name', $locale),
            'engine' => $configuration['engine'] ?? 'request', 'layout' => $configuration['layout'] ?? 'general', 'navigation' => $configuration['navigation'] ?? ['home', 'works', 'action', 'activity', 'profile'],
            'hero' => $this->localized($configuration['hero'] ?? [], $locale), 'trust' => array_map(fn ($item) => $this->localized($item, $locale), $configuration['trust'] ?? []),
            'media_slots' => $slots, 'video' => $media['video'] ?? $configuration['video'] ?? [], 'fields' => $fields,
            'submit' => $this->localized($configuration['submit'] ?? ['label' => $configuration['submit_label'] ?? null], $locale),
            'success' => $this->localized($configuration['success'] ?? [], $locale), 'push_prompt' => $this->localized($configuration['push_prompt'] ?? [], $locale),
            'capabilities' => $configuration['capabilities'] ?? ['request' => true],
        ];
    }

    private function localizedSlots(array $slots, string $locale): array
    {
        $labels = [
            'overall' => ['de' => 'Lenkrad komplett', 'en' => 'Whole steering wheel', 'ru' => 'Руль целиком', 'uk' => 'Кермо повністю'],
            'top' => ['de' => 'Oberer Bereich', 'en' => 'Top section', 'ru' => 'Верх руля', 'uk' => 'Верх керма'],
            'left' => ['de' => 'Linke Seite', 'en' => 'Left side', 'ru' => 'Левая сторона', 'uk' => 'Ліва сторона'],
            'right' => ['de' => 'Rechte Seite', 'en' => 'Right side', 'ru' => 'Правая сторона', 'uk' => 'Права сторона'],
            'bottom' => ['de' => 'Unterer Bereich', 'en' => 'Bottom section', 'ru' => 'Нижняя часть', 'uk' => 'Нижня частина'],
            'damage' => ['de' => 'Schaden im Detail', 'en' => 'Damage detail', 'ru' => 'Повреждение крупно', 'uk' => 'Пошкодження зблизька'],
            'reference' => ['de' => 'Wunschbeispiel', 'en' => 'Reference example', 'ru' => 'Пример желаемого результата', 'uk' => 'Приклад бажаного результату'],
            'opening_overall' => ['de' => 'Türöffnung komplett', 'en' => 'Whole doorway', 'ru' => 'Проём целиком', 'uk' => 'Отвір повністю'],
            'opening_left' => ['de' => 'Linke Seite', 'en' => 'Left side', 'ru' => 'Левая сторона', 'uk' => 'Ліва сторона'],
            'opening_right' => ['de' => 'Rechte Seite', 'en' => 'Right side', 'ru' => 'Правая сторона', 'uk' => 'Права сторона'],
            'opening_top' => ['de' => 'Oberer Bereich', 'en' => 'Top section', 'ru' => 'Верх проёма', 'uk' => 'Верх отвору'],
            'floor_threshold' => ['de' => 'Boden und Schwelle', 'en' => 'Floor and threshold', 'ru' => 'Пол и порог', 'uk' => 'Підлога й поріг'],
            'existing_door' => ['de' => 'Vorhandene Tür', 'en' => 'Existing door', 'ru' => 'Старая дверь', 'uk' => 'Старі двері'],
            'problem_detail' => ['de' => 'Problemstelle', 'en' => 'Problem detail', 'ru' => 'Сложное место', 'uk' => 'Складне місце'],
            'reference_door' => ['de' => 'Gewünschte Tür', 'en' => 'Preferred door', 'ru' => 'Выбранная дверь', 'uk' => 'Обрані двері'],
        ];

        return array_map(function (array $slot) use ($labels, $locale): array {
            $label = $labels[$slot['key'] ?? ''][$locale] ?? $slot['title'] ?? $slot['label'] ?? null;
            $slot['title'] = $label;
            $slot['label'] = $label;

            return $slot;
        }, $slots);
    }

    private function localizedFields(array $fields, string $locale): array
    {
        $labels = [
            'vehicle_brand' => ['de' => 'Automarke', 'en' => 'Vehicle brand', 'ru' => 'Марка автомобиля', 'uk' => 'Марка автомобіля'], 'vehicle_model' => ['de' => 'Modell', 'en' => 'Model', 'ru' => 'Модель', 'uk' => 'Модель'], 'vehicle_year' => ['de' => 'Baujahr', 'en' => 'Year', 'ru' => 'Год', 'uk' => 'Рік'],
            'material_preference' => ['de' => 'Materialwunsch', 'en' => 'Material preference', 'ru' => 'Пожелания по материалу', 'uk' => 'Побажання щодо матеріалу'], 'stitch_preference' => ['de' => 'Nahtwunsch', 'en' => 'Stitch preference', 'ru' => 'Пожелания по строчке', 'uk' => 'Побажання щодо строчки'], 'shape_preference' => ['de' => 'Form oder Dicke ändern?', 'en' => 'Change shape or thickness?', 'ru' => 'Изменить форму или толщину?', 'uk' => 'Змінити форму або товщину?'],
            'opening_width_mm' => ['de' => 'Breite der Öffnung (mm)', 'en' => 'Opening width (mm)', 'ru' => 'Ширина проёма (мм)', 'uk' => 'Ширина отвору (мм)'], 'opening_height_mm' => ['de' => 'Höhe der Öffnung (mm)', 'en' => 'Opening height (mm)', 'ru' => 'Высота проёма (мм)', 'uk' => 'Висота отвору (мм)'], 'wall_thickness_mm' => ['de' => 'Wandstärke (mm)', 'en' => 'Wall thickness (mm)', 'ru' => 'Толщина стены (мм)', 'uk' => 'Товщина стіни (мм)'], 'door_request_type' => ['de' => 'Was soll gemacht werden?', 'en' => 'What should be done?', 'ru' => 'Что нужно сделать?', 'uk' => 'Що потрібно зробити?'], 'door_type' => ['de' => 'Türart', 'en' => 'Door type', 'ru' => 'Тип двери', 'uk' => 'Тип дверей'], 'comment' => ['de' => 'Ihre Wünsche', 'en' => 'Your notes', 'ru' => 'Ваши пожелания', 'uk' => 'Ваші побажання'], 'phone' => ['de' => 'Telefonnummer', 'en' => 'Phone number', 'ru' => 'Номер телефона', 'uk' => 'Номер телефону'],
        ];
        $options = [
            'Не знаю — посоветует мастер' => ['de' => 'Ich weiß es nicht – der Meister empfiehlt etwas', 'en' => 'Not sure — the specialist will advise', 'uk' => 'Не знаю — майстер порадить'], 'Кожа' => ['de' => 'Leder', 'en' => 'Leather', 'uk' => 'Шкіра'], 'Перфорированная кожа' => ['de' => 'Perforiertes Leder', 'en' => 'Perforated leather', 'uk' => 'Перфорована шкіра'], 'Комбинация' => ['de' => 'Kombination', 'en' => 'Combination', 'uk' => 'Комбінація'], 'Другое' => ['de' => 'Andere', 'en' => 'Other', 'uk' => 'Інше'],
            'Межкомнатная' => ['de' => 'Innentür', 'en' => 'Interior door', 'uk' => 'Міжкімнатні'], 'Входная' => ['de' => 'Eingangstür', 'en' => 'Entrance door', 'uk' => 'Вхідні'], 'Раздвижная' => ['de' => 'Schiebetür', 'en' => 'Sliding door', 'uk' => 'Розсувні'], 'Ещё не выбрал(а)' => ['de' => 'Noch nicht ausgewählt', 'en' => 'Not chosen yet', 'uk' => 'Ще не обрано'],
        ];

        return array_map(function (array $field) use ($labels, $options, $locale): array {
            $field['label'] = $labels[$field['key'] ?? ''][$locale] ?? $field['label'] ?? $field['key'];
            if (isset($field['options'])) {
                $field['options'] = array_map(fn ($option) => $options[$option][$locale] ?? $option, $field['options']);
            }

            return $field;
        }, $fields);
    }

    private function servicePayload(TenantService $service, string $locale): array
    {
        return ['id' => $service->id, 'name' => $service->localized('name', $locale), 'description' => $service->localized('description', $locale), 'duration' => $service->duration_minutes, 'price' => $service->price, 'currency' => $service->currency];
    }

    private function requestPayload(TenantRequest $request): array
    {
        $request->loadMissing(['media', 'messages']);

        return ['id' => $request->id, 'number' => $request->number, 'status' => $request->status, 'summary' => $request->summary, 'created_at' => $request->created_at?->toIso8601String(), 'media' => $request->media->map(fn ($item) => ['id' => $item->id, 'type' => $item->type, 'slot' => $item->slot_key, 'url' => Storage::disk('public')->url($item->storage_key)]), 'messages' => $request->messages->map(fn ($item) => $this->messagePayload($item))];
    }

    private function appointmentPayload(TenantAppointment $appointment, string $locale): array
    {
        return ['id' => $appointment->id, 'number' => $appointment->number, 'status' => $appointment->status, 'starts_at' => $appointment->starts_at?->toIso8601String(), 'ends_at' => $appointment->ends_at?->toIso8601String(), 'service' => $appointment->service ? $this->servicePayload($appointment->service, $locale) : null];
    }

    private function messagePayload(TenantMessage $message): array
    {
        return ['id' => $message->id, 'sender' => $message->sender_type, 'body' => $message->body, 'created_at' => $message->created_at?->toIso8601String()];
    }

    private function localized(mixed $value, string $locale): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_intersect(array_keys($value), ['de', 'en', 'ru', 'uk'])) {
            return $value[$locale] ?? $value['de'] ?? $value['en'] ?? $value['ru'] ?? reset($value);
        }

        return array_map(fn ($item) => $this->localized($item, $locale), $value);
    }

    private function assetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http') || str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function number(string $prefix): string
    {
        return $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
    }

    private function message(string $key, string $locale): string
    {
        $messages = ['received' => ['de' => 'Ihre Anfrage ist angekommen. Der Meister prüft die Angaben und antwortet hier.', 'en' => 'Your request has arrived. The specialist will review it and reply here.', 'ru' => 'Ваша заявка получена. Мастер изучит её и ответит здесь.', 'uk' => 'Ваш запит отримано. Майстер перегляне його й відповість тут.']];

        return $messages[$key][$locale] ?? $messages[$key]['de'];
    }
}

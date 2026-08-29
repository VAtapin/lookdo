<?php

namespace App\Http\Controllers;

use App\Jobs\SendTenantMasterPush;
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
use App\Services\ImageStorageService;
use App\Services\TenantCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        $this->ensureAvailable($request, $tenant);
        $tenant->load(['profile', 'businessProfile.category', 'businessProfile.variation', 'businessProfile.template', 'currentSubscription.plan.entitlements']);
        $configuration = $this->configuration($tenant);
        $this->seedDefaults($tenant, $configuration);
        $locale = $this->locale($request, $tenant);
        $customer = $this->customerFromToken($request, $tenant);

        return response()->json([
            'tenant' => $this->tenantPayload($tenant, $locale),
            'template' => $this->templatePayload($tenant, $configuration, $locale),
            'services' => $tenant->services()->where('active', true)->orderBy('sort_order')->get()->map(fn (TenantService $service) => $this->servicePayload($service, $locale)),
            'portfolio' => $tenant->portfolioItems()->where('published', true)->where(function ($query) {
                $query->where('image_path', '!=', '/brand/leonid-demo.webp')->orWhereNull('image_path')->orWhereNotNull('before_image_path')->orWhereNotNull('after_image_path');
            })->orderByDesc('featured')->orderBy('sort_order')->get()->map(fn ($item) => [
                'id' => $item->id, 'title' => $item->localized('title', $locale), 'description' => $item->localized('description', $locale),
                'image' => $this->assetUrl($item->image_path), 'before_image' => $this->assetUrl($item->before_image_path), 'after_image' => $this->assetUrl($item->after_image_path), 'featured' => $item->featured,
            ]),
            'reviews' => $tenant->reviews()->with('customer')->where('published', true)->latest('received_at')->get()->map(fn ($review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'author' => $review->author_name ?: $review->customer?->name,
                'body' => $review->body,
                'master_reply' => $review->master_reply,
                'replied_at' => $review->replied_at?->toIso8601String(),
                'received_at' => $review->received_at?->toIso8601String(),
            ]),
            'entitlements' => [
                'requests' => $this->enabled($tenant, 'request_enabled', true), 'booking' => $this->enabled($tenant, 'booking_enabled', false),
                'video' => $this->enabled($tenant, 'video_enabled', false), 'push' => $this->enabled($tenant, 'push_enabled', true),
            ],
            'push' => ['enabled' => $this->enabled($tenant, 'push_enabled', true) && filled(config('services.webpush.vapid_public_key')), 'public_key' => (string) config('services.webpush.vapid_public_key', '')],
            'session' => ['known' => (bool) $customer, 'customer' => $customer ? ['name' => $customer->name, 'phone' => $customer->phone, 'locale' => $customer->locale] : null],
        ]);
    }

    public function createRequest(Request $request, ImageStorageService $images): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
        if (! $this->enabled($tenant, 'request_enabled', true)) {
            $this->apiError($request, $tenant, 'TENANT_APP_REQUESTS_DISABLED', 'tenant_app.requests_disabled', 403);
        }
        $limit = (int) $this->entitlements->get($tenant, 'requests_monthly', 0);
        if ($limit > 0 && $tenant->appRequests()->where('created_at', '>=', now()->startOfMonth())->count() >= $limit) {
            $this->apiError($request, $tenant, 'TENANT_APP_REQUEST_LIMIT_REACHED', 'tenant_app.request_limit_reached', 429);
        }

        $data = $request->validate([
            'name' => 'nullable|string|max:120', 'phone' => 'required|string|max:50', 'email' => 'nullable|email|max:190',
            'preferred_channel' => 'nullable|in:phone,whatsapp,sms,email,push,vk', 'summary' => 'nullable|string|max:5000',
            'fields' => 'nullable', 'media_slots' => 'nullable', 'media' => 'required|array|min:1|max:12',
            'media.*' => 'file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime|max:262144',
        ]);
        $fields = $this->jsonArray($data['fields'] ?? []);
        $slots = $this->jsonArray($data['media_slots'] ?? []);
        $locale = $this->locale($request, $tenant);
        [$customer, $rawToken] = $this->customerAndToken($request, $tenant, $data, $locale);

        $tenantRequest = DB::transaction(function () use ($tenant, $customer, $data, $fields, $slots, $locale, $request, $images) {
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
                if ($type === 'video' && ! $this->enabled($tenant, 'video_enabled', false)) {
                    $this->apiError($request, $tenant, 'TENANT_APP_VIDEO_DISABLED', 'tenant_app.video_disabled', 403);
                }
                if ($type === 'image' && $file->getSize() > 25 * 1024 * 1024) {
                    throw ValidationException::withMessages(['media.'.$index => 'Images may not be larger than 25 MB.']);
                }
                $directory = "tenant-app/{$tenant->id}/requests/{$appRequest->id}";
                $path = $type === 'image'
                    ? $images->storeUploaded($file, $directory, 'public', 2048, 2048)
                    : $file->store($directory, 'public');
                $appRequest->media()->create([
                    'tenant_id' => $tenant->id, 'type' => $type, 'role' => 'condition',
                    'slot_key' => $slots[$index] ?? null, 'sort_order' => $index, 'storage_key' => $path,
                    'metadata' => [
                        'mime' => Storage::disk('public')->mimeType($path) ?: $file->getMimeType(),
                        'size' => Storage::disk('public')->size($path),
                    ],
                ]);
            }
            $appRequest->messages()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'sender_type' => 'system', 'body' => $this->message('received', $locale)]);

            return $appRequest;
        });

        $this->notifyMaster($tenant, 'new_request', '/app/requests', 'request-'.$tenantRequest->id);

        return response()->json(['token' => $rawToken, 'request' => $this->requestPayload($tenantRequest->fresh(['media', 'messages'])), 'success' => $this->localized(data_get($this->configuration($tenant), 'success', []), $locale)], 201);
    }

    public function activity(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
        $customer = $this->requireCustomer($request, $tenant);
        $requests = $customer->requests()->where('tenant_id', $tenant->id)->with(['media', 'messages'])->latest()->get()->map(fn ($item) => $this->requestPayload($item));
        $appointments = $customer->appointments()->where('tenant_id', $tenant->id)->with('service')->latest('starts_at')->get()->map(fn ($item) => $this->appointmentPayload($item, $customer->locale ?: $tenant->locale));

        return response()->json(['requests' => $requests, 'appointments' => $appointments]);
    }

    public function postMessage(Request $request, TenantRequest $tenantRequest): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
        $customer = $this->requireCustomer($request, $tenant);
        abort_unless($tenantRequest->tenant_id === $tenant->id && $tenantRequest->customer_id === $customer->id, 404);
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $message = $tenantRequest->messages()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'sender_type' => 'customer', 'body' => $data['body']]);
        $customer->update(['last_activity_at' => now()]);
        $this->notifyMaster($tenant, 'new_message', '/app/messages', 'message-'.$message->id);

        return response()->json(['message' => $this->messagePayload($message)], 201);
    }

    public function availability(Request $request, TenantCalendarService $calendar): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
        if (! $this->enabled($tenant, 'booking_enabled', false)) {
            $this->apiError($request, $tenant, 'TENANT_APP_BOOKING_DISABLED', 'tenant_app.booking_disabled', 403);
        }
        $data = $request->validate([
            'service_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'appointment_id' => 'nullable|integer',
            'resource_id' => 'nullable|integer',
        ]);
        $service = $tenant->services()->where('active', true)->findOrFail($data['service_id']);
        $exceptAppointmentId = null;
        if (filled($data['appointment_id'] ?? null)) {
            $customer = $this->requireCustomer($request, $tenant);
            $appointment = $tenant->appointments()->where('customer_id', $customer->id)->findOrFail($data['appointment_id']);
            $exceptAppointmentId = $appointment->id;
        }

        $resourceIds = filled($data['resource_id'] ?? null)
            ? [$tenant->resources()->where('active', true)->findOrFail($data['resource_id'])->id]
            : $tenant->resources()->where('active', true)->orderBy('sort_order')->pluck('id')->all();
        if ($resourceIds === []) {
            $resourceIds = [null];
        }
        $slots = collect($resourceIds)->flatMap(fn ($resourceId) => collect($calendar->slots($tenant, $service, $data['date'], $exceptAppointmentId, $resourceId))
            ->map(fn ($slot) => array_merge($slot, ['resource_id' => $resourceId])))
            ->sortBy('starts_at')->unique('starts_at')->values();

        return response()->json(['slots' => $slots]);
    }

    public function createAppointment(Request $request, TenantCalendarService $calendar): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
        if (! $this->enabled($tenant, 'booking_enabled', false)) {
            $this->apiError($request, $tenant, 'TENANT_APP_BOOKING_DISABLED', 'tenant_app.booking_disabled', 403);
        }
        $data = $request->validate([
            'service_id' => 'required|integer', 'starts_at' => 'required|date|after:now', 'name' => 'nullable|string|max:120',
            'phone' => 'required|string|max:50', 'email' => 'nullable|email|max:190', 'comment' => 'nullable|string|max:2000',
            'preferred_channel' => 'nullable|in:phone,whatsapp,viber,telegram,sms,email,push,vk',
            'resource_id' => 'nullable|integer',
        ]);
        $service = $tenant->services()->where('active', true)->where('booking_enabled', true)->findOrFail($data['service_id']);
        $locale = $this->locale($request, $tenant);
        [$customer, $rawToken] = $this->customerAndToken($request, $tenant, $data, $locale);
        $start = CarbonImmutable::parse($data['starts_at'], 'Europe/Berlin');
        $end = $start->addMinutes($service->duration_minutes);
        $resourceId = filled($data['resource_id'] ?? null)
            ? $tenant->resources()->where('active', true)->findOrFail($data['resource_id'])->id
            : $tenant->resources()->where('active', true)->orderBy('sort_order')->get()->first(function ($resource) use ($calendar, $tenant, $service, $start): bool {
                return collect($calendar->slots($tenant, $service, $start->toDateString(), null, $resource->id))
                    ->contains(fn ($slot) => CarbonImmutable::parse($slot['starts_at'])->equalTo($start));
            })?->id;
        if ($resourceId === null && $tenant->resources()->where('active', true)->exists()) {
            throw ValidationException::withMessages(['starts_at' => trans('tenant_app.slot_unavailable', locale: $locale)]);
        }

        $appointment = DB::transaction(function () use ($tenant, $customer, $service, $data, $locale, $start, $end, $calendar, $resourceId) {
            try {
                $calendar->assertAvailable($tenant, $service, $start, $end, null, $resourceId);
            } catch (ValidationException) {
                throw ValidationException::withMessages(['starts_at' => trans('tenant_app.slot_unavailable', locale: $locale)]);
            }

            return $tenant->appointments()->create([
                'customer_id' => $customer->id, 'service_id' => $service->id, 'resource_id' => $resourceId, 'number' => $this->number('A'), 'status' => 'pending',
                'starts_at' => $start, 'ends_at' => $end, 'comment' => $data['comment'] ?? null, 'locale' => $locale,
                'contact_snapshot' => Arr::only($data, ['name', 'phone', 'email', 'preferred_channel']),
            ]);
        });

        $this->notifyMaster($tenant, 'new_appointment', '/app/calendar', 'appointment-'.$appointment->id);

        return response()->json(['token' => $rawToken, 'appointment' => $this->appointmentPayload($appointment->load('service'), $locale)], 201);
    }

    public function rescheduleAppointment(Request $request, TenantAppointment $tenantAppointment, TenantCalendarService $calendar): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
        $customer = $this->requireCustomer($request, $tenant);
        abort_unless($tenantAppointment->tenant_id === $tenant->id && $tenantAppointment->customer_id === $customer->id, 404);
        abort_if(in_array($tenantAppointment->status, ['cancelled', 'completed', 'no_show'], true), 409);
        $data = $request->validate(['starts_at' => 'required|date|after:now']);
        $tenantAppointment->load('service');
        abort_unless($tenantAppointment->service?->active && $tenantAppointment->service?->booking_enabled, 409);
        $start = CarbonImmutable::parse($data['starts_at'], TenantCalendarService::TIMEZONE);
        $end = $start->addMinutes($tenantAppointment->service->duration_minutes);

        DB::transaction(function () use ($tenant, $tenantAppointment, $calendar, $start, $end) {
            $calendar->assertAvailable($tenant, $tenantAppointment->service, $start, $end, $tenantAppointment->id, $tenantAppointment->resource_id);
            $tenantAppointment->update(['starts_at' => $start, 'ends_at' => $end, 'status' => 'confirmed']);
        });

        $this->notifyMaster($tenant, 'new_appointment', '/app/calendar', 'appointment-rescheduled-'.$tenantAppointment->id);

        return response()->json(['appointment' => $this->appointmentPayload($tenantAppointment->fresh('service'), $customer->locale ?: $tenant->locale)]);
    }

    public function cancelAppointment(Request $request, TenantAppointment $tenantAppointment): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
        $customer = $this->requireCustomer($request, $tenant);
        abort_unless($tenantAppointment->tenant_id === $tenant->id && $tenantAppointment->customer_id === $customer->id, 404);
        abort_if(in_array($tenantAppointment->status, ['completed', 'no_show'], true), 409);
        $tenantAppointment->update(['status' => 'cancelled']);

        return response()->json(['appointment' => $this->appointmentPayload($tenantAppointment->fresh('service'), $customer->locale ?: $tenant->locale)]);
    }

    public function subscribePush(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
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

    public function submitReview(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
        $customer = $this->requireCustomer($request, $tenant);
        $data = $request->validate([
            'request_id' => 'nullable|integer',
            'rating' => 'required|integer|between:1,5',
            'body' => 'required|string|max:3000',
            'author_name' => 'nullable|string|max:120',
        ]);
        $tenantRequest = filled($data['request_id'] ?? null)
            ? $tenant->appRequests()->where('customer_id', $customer->id)->findOrFail($data['request_id'])
            : null;
        $review = $tenant->reviews()->updateOrCreate(
            ['customer_id' => $customer->id, 'request_id' => $tenantRequest?->id],
            [
                'rating' => $data['rating'],
                'body' => $data['body'],
                'author_name' => $data['author_name'] ?: $customer->name,
                'published' => false,
                'received_at' => now(),
            ],
        );

        return response()->json(['review' => $review, 'message' => trans('tenant_app.review_received', locale: $this->locale($request, $tenant))], 201);
    }

    private function notifyMaster(Tenant $tenant, string $event, string $url, string $tag): void
    {
        if (! $this->enabled($tenant, 'push_enabled', true)) {
            return;
        }

        $locale = in_array($tenant->locale, ['de', 'en', 'ru', 'uk'], true) ? $tenant->locale : 'de';
        SendTenantMasterPush::dispatch($tenant->id, [
            'title' => trans("tenant_app.master_push.$event.title", locale: $locale),
            'body' => trans("tenant_app.master_push.$event.body", locale: $locale),
            'url' => $url,
            'tag' => "lookdo-master-$tag",
            'action' => trans('tenant_app.master_push.open', locale: $locale),
        ])->afterResponse();
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function ensureAvailable(Request $request, Tenant $tenant): void
    {
        $locale = $this->locale($request, $tenant);

        if ($tenant->status !== 'active') {
            $this->unavailable('TENANT_APP_INACTIVE', 'tenant_app.inactive', $locale, 404);
        }
        if (! $tenant->hasActiveSubscription()) {
            $this->unavailable('TENANT_APP_SUBSCRIPTION_INACTIVE', 'tenant_app.subscription_inactive', $locale, 402);
        }
    }

    private function unavailable(string $code, string $translationKey, string $locale, int $status): never
    {
        $this->localizedError($code, $translationKey, $locale, $status);
    }

    private function apiError(Request $request, Tenant $tenant, string $code, string $translationKey, int $status): never
    {
        $this->localizedError($code, $translationKey, $this->locale($request, $tenant), $status);
    }

    private function localizedError(string $code, string $translationKey, string $locale, int $status): never
    {
        throw new HttpResponseException(response()->json([
            'code' => $code,
            'locale' => $locale,
            'message' => trans($translationKey, locale: $locale),
        ], $status)->header('Content-Language', $locale));
    }

    private function configuration(Tenant $tenant): array
    {
        $tenant->loadMissing('businessProfile.template');
        $presets = (array) config('tenant_apps.templates', []);
        $template = $tenant->businessProfile?->template;

        return $template
            ? $template->resolvedConfiguration($presets)
            : (array) ($presets['general-services.general'] ?? []);
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
        $starterServices = (array) ($configuration['starter_services'] ?? []);
        if ($tenant->services()->doesntExist()) {
            foreach ($starterServices as $index => $service) {
                $tenant->services()->create(['name' => $service['name'], 'description' => $service['description'] ?? [], 'image_path' => $service['image'] ?? null, 'duration_minutes' => $service['duration'] ?? 60, 'booking_enabled' => true, 'active' => true, 'sort_order' => $index * 10]);
            }
        } else {
            foreach ($starterServices as $index => $service) {
                $existing = $tenant->services()->where('sort_order', $index * 10)->first();
                if ($existing && blank($existing->image_path) && filled($service['image'] ?? null)) {
                    $existing->update(['image_path' => $service['image']]);
                }
            }
        }
        if ($tenant->portfolioItems()->doesntExist()) {
            foreach ((array) ($configuration['starter_portfolio'] ?? []) as $index => $item) {
                $tenant->portfolioItems()->create(['title' => $item['title'], 'description' => $item['description'] ?? [], 'image_path' => $item['image'] ?? null, 'featured' => $item['featured'] ?? false, 'published' => true, 'sort_order' => $index * 10]);
            }
        }
    }

    /**
     * A phone number is only a duplicate signal. It never grants access to another
     * device's history. Known devices keep their customer; unknown devices get a
     * separate record which the master may merge after verification.
     */
    private function customerAndToken(Request $request, Tenant $tenant, array $data, string $locale): array
    {
        $customer = $this->customerFromToken($request, $tenant);
        $rawToken = null;
        $normalized = preg_replace('/\D+/', '', (string) $data['phone']);

        if (! $customer) {
            $possibleDuplicate = $normalized !== ''
                ? $tenant->customers()->where('phone_normalized', $normalized)->latest('id')->first()
                : null;
            $customer = $tenant->customers()->create([
                'possible_duplicate_of_id' => $possibleDuplicate?->id,
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'],
                'phone_normalized' => $normalized,
                'email' => $data['email'] ?? null,
                'locale' => $locale,
                'preferred_channel' => $data['preferred_channel'] ?? 'phone',
                'last_activity_at' => now(),
                'service_consent_at' => now(),
            ]);
            $rawToken = Str::random(80);
            $customer->tokens()->create([
                'tenant_id' => $tenant->id,
                'token_hash' => hash('sha256', $rawToken),
                'last_used_at' => now(),
                'expires_at' => now()->addYear(),
            ]);
        } else {
            $customer->fill([
                'name' => $data['name'] ?? $customer->name,
                'phone' => $data['phone'],
                'phone_normalized' => $normalized,
                'email' => $data['email'] ?? $customer->email,
                'locale' => $locale,
                'preferred_channel' => $data['preferred_channel'] ?? $customer->preferred_channel ?? 'phone',
                'last_activity_at' => now(),
            ])->save();
        }

        return [$customer, $rawToken ?: (string) $request->header('X-Lookdo-Client-Token')];
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
        $customer = $this->customerFromToken($request, $tenant);
        if (! $customer) {
            $this->apiError($request, $tenant, 'TENANT_APP_DEVICE_UNLINKED', 'tenant_app.device_unlinked', 401);
        }

        return $customer;
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

        $branding = (array) data_get($profile?->content, 'branding', []);

        return [
            'id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'locale' => $locale,
            'description' => $tenant->business_description, 'logo' => $this->assetUrl($profile?->logo_path),
            'colors' => ['primary' => $profile?->primary_color ?: '#ff6b00', 'secondary' => $profile?->secondary_color ?: '#111318'],
            'contact' => [
                'name' => $profile?->contact_name,
                'phone' => $profile?->phone,
                'email' => $profile?->email,
                'street' => $profile?->street,
                'postal_code' => $profile?->postal_code,
                'city' => $profile?->city,
                'vk_url' => $branding['vk_url'] ?? null,
                'working_hours' => $branding['working_hours'] ?? null,
            ],
            'branding' => [
                'confirmed' => filled($branding['confirmed_at'] ?? null),
                'tagline' => $branding['tagline'] ?? null,
                'hero_image' => $this->assetUrl($branding['hero_image_path'] ?? null),
            ],
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
            'theme' => $configuration['theme'] ?? [],
            'hero' => array_replace(
                (array) $this->localized($configuration['hero'] ?? [], $locale),
                filled(data_get($tenant->profile?->content, 'branding.hero_image_path'))
                    ? ['image' => $this->assetUrl(data_get($tenant->profile?->content, 'branding.hero_image_path'))]
                    : [],
            ), 'trust' => array_map(fn ($item) => $this->localized($item, $locale), $configuration['trust'] ?? []),
            'media_slots' => $slots, 'video' => $media['video'] ?? $configuration['video'] ?? [], 'fields' => $fields,
            'submit' => $this->localized($configuration['submit'] ?? ['label' => $configuration['submit_label'] ?? null], $locale),
            'success' => $this->localized($configuration['success'] ?? [], $locale), 'push_prompt' => $this->localized($configuration['push_prompt'] ?? [], $locale),
            'screens' => collect($configuration['screens'] ?? [])->map(function ($screen) use ($locale) {
                $screen['name'] = $this->localized($screen['name'] ?? $screen['key'] ?? '', $locale);
                $screen['blocks'] = collect($screen['blocks'] ?? [])->filter(fn ($block) => ($block['enabled'] ?? true) === true)->map(function ($block) use ($locale) {
                    $block['title'] = $this->localized($block['title'] ?? '', $locale);

                    return $block;
                })->values()->all();

                return $screen;
            })->values()->all(),
            'actions' => collect($configuration['actions'] ?? [])->filter(fn ($action) => ($action['enabled'] ?? true) === true)->map(function ($action) use ($locale) {
                $action['label'] = $this->localized($action['label'] ?? '', $locale);

                return $action;
            })->values()->all(),
            'locales' => array_values(array_intersect(['de', 'en', 'ru', 'uk'], $configuration['locales'] ?? ['de', 'en', 'ru', 'uk'])),
            'capabilities' => $configuration['capabilities'] ?? ['request' => true],
        ];
    }

    private function localizedSlots(array $slots, string $locale): array
    {
        $labels = trans('tenant_app.media_slots', locale: $locale);
        $labels = is_array($labels) ? $labels : [];

        return array_map(function (array $slot) use ($labels): array {
            $label = $labels[$slot['key'] ?? ''] ?? $slot['title'] ?? $slot['label'] ?? null;
            $slot['title'] = $label;
            $slot['label'] = $label;

            return $slot;
        }, $slots);
    }

    private function localizedFields(array $fields, string $locale): array
    {
        $labels = trans('tenant_app.fields', locale: $locale);
        $options = trans('tenant_app.options', locale: $locale);
        $labels = is_array($labels) ? $labels : [];
        $options = is_array($options) ? $options : [];

        return array_map(function (array $field) use ($labels, $options): array {
            $field['label'] = $labels[$field['key'] ?? ''] ?? $field['label'] ?? $field['key'];
            if (isset($field['options'])) {
                $field['options'] = array_map(fn ($option) => $options[$option] ?? $option, $field['options']);
            }

            return $field;
        }, $fields);
    }

    private function servicePayload(TenantService $service, string $locale): array
    {
        return ['id' => $service->id, 'name' => $service->localized('name', $locale), 'description' => $service->localized('description', $locale), 'image' => $this->assetUrl($service->image_path), 'duration' => $service->duration_minutes, 'price' => $service->price, 'currency' => $service->currency];
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
        return trans('tenant_app.'.$key, locale: $locale);
    }
}

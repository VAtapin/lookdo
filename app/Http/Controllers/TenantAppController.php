<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeTenantRequestMedia;
use App\Jobs\SendPlatformAdminNewRequestNotification;
use App\Jobs\SendTenantMasterPush;
use App\Mail\TenantNotificationMail;
use App\Models\Tenant;
use App\Models\TenantAppointment;
use App\Models\TenantClientToken;
use App\Models\TenantCustomer;
use App\Models\TenantMessage;
use App\Models\TenantPushSubscription;
use App\Models\TenantRequest;
use App\Models\TenantRequestValue;
use App\Models\TenantService;
use App\Services\BookCatalogService;
use App\Services\BookPurchasePricingService;
use App\Services\EntitlementService;
use App\Services\ImageStorageService;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use App\Services\SmsService;
use App\Services\TenantCalendarService;
use App\Services\TenantWebPushService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TenantAppController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function bootstrap(Request $request, TenantWebPushService $webPush): JsonResponse
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
            'portfolio' => $tenant->portfolioItems()->where('published', true)->orderByDesc('featured')->orderBy('sort_order')->get()->map(fn ($item) => [
                'id' => $item->id, 'title' => $item->localized('title', $locale), 'description' => $item->localized('description', $locale),
                'image' => $this->assetUrl($item->image_path), 'video' => $this->assetUrl($item->video_path), 'before_image' => $this->assetUrl($item->before_image_path), 'after_image' => $this->assetUrl($item->after_image_path), 'featured' => $item->featured,
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
            'push' => ['enabled' => $this->enabled($tenant, 'push_enabled', true) && $webPush->configured(), 'public_key' => $webPush->configured() ? (string) config('services.webpush.vapid_public_key', '') : ''],
            'session' => [
                'known' => (bool) $customer,
                'customer' => $customer ? ['name' => $customer->name, 'phone' => $customer->phone, 'locale' => $customer->locale] : null,
                'review' => $this->reviewState($tenant, $customer),
            ],
        ]);
    }

    public function assistRequest(Request $request, OpenAiService $openAi, OpenAiBudgetService $budget, BookCatalogService $books, BookPurchasePricingService $pricing): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
        $data = $request->validate([
            'text' => 'nullable|string|max:2000',
            'media' => 'nullable|array|max:6',
            'media.*' => 'file|mimetypes:image/jpeg,image/png,image/webp|max:10240',
            'media_slots' => 'nullable|string|max:2000',
            'current_fields' => 'nullable|string|max:20000',
            'isbn_absent' => 'nullable|boolean',
        ]);
        $uploaded = $request->file('media', []);
        if (mb_strlen(trim((string) ($data['text'] ?? ''))) < 3 && $uploaded === []) {
            throw ValidationException::withMessages(['text' => 'Add a short note or at least one photo.']);
        }
        $locale = $this->locale($request, $tenant);
        $configuration = $this->configuration($tenant);
        $variationCode = (string) ($tenant->businessProfile?->variation?->code ?? '');
        $isBookPurchase = $variationCode === 'purchase.books';
        $isbnAbsent = (bool) ($data['isbn_absent'] ?? false);
        $mediaSlots = $this->jsonArray($data['media_slots'] ?? []);
        $currentFields = $this->jsonArray($data['current_fields'] ?? []);
        $fields = collect((array) ($configuration['fields'] ?? []))
            ->filter(fn (array $field) => filled($field['key'] ?? null) && ($field['key'] ?? null) !== 'phone')
            ->values();
        $properties = ['summary' => ['type' => 'string']];
        foreach ($fields as $field) {
            $properties[(string) $field['key']] = ['type' => 'string'];
        }
        if ($isBookPurchase) {
            $properties['condition_grade'] = ['type' => 'string', 'enum' => ['poor', 'fair', 'good', 'very_good', 'unknown']];
            $properties['master_comment'] = ['type' => 'string'];
            $properties['recommended_purchase_price'] = ['type' => 'number', 'minimum' => 0];
            $properties['price_currency'] = ['type' => 'string'];
        }
        $fieldContext = $fields->map(fn (array $field) => [
            'key' => $field['key'],
            'label' => $this->localized($field['label'] ?? $field['key'], $locale),
        ])->all();
        $budget->ensureAvailable();
        $instructions = $isBookPurchase
            ? 'Analyze the uploaded book photos immediately for a professional book buyer. OCR an ISBN-10 or ISBN-13 exactly when visible, including its check digit. Fill every reliably inferable form field, including visible binding, spine, page condition, completeness, stains, inscriptions, signatures and defects. The master_comment is a separate practical note of 3 to 5 compact sentences and no more than 600 characters: visible condition of binding, spine and pages, important strengths or defects, and the most relevant point still needing a check. Do not repeat title, author, ISBN, publisher, year, edition, page references, listing description or other form data in master_comment. Never mention Google Books, catalogues, technical identification methods, AI, internal recommendations, valuation disclaimers or guarantees. Recommend a deliberately conservative low purchase price. Never invent an ISBN or claim a defect that is not visible. Use empty strings for unknown text fields. If the user states that no ISBN exists, leave ISBN empty and assess from the other photos.'
            : 'Help a customer fill a request for '.($this->localized(data_get($configuration, 'condition_assessment.context', 'a local specialist'), $locale)).'. Read visible identifiers such as ISBN, VIN, maker marks and labels carefully. Preserve facts, never invent missing bibliographic, vehicle, provenance or condition details, and use empty strings for unknown facts. Return concise values in '.$locale.'.';
        $input = json_encode(['customer_note' => (string) ($data['text'] ?? ''), 'fields' => $fieldContext, 'current_values' => $currentFields, 'photo_slots' => $mediaSlots, 'isbn_absent' => $isbnAbsent], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties)];
        $images = collect($uploaded)->map(fn ($file) => [
            'contents' => (string) file_get_contents($file->getRealPath()),
            'mime' => (string) $file->getMimeType(),
        ])->all();
        $result = $images !== []
            ? $openAi->structuredWithImages($instructions, $input, 'tenant_request_assistance', $schema, $images)
            : $openAi->structured($instructions, $input, 'tenant_request_assistance', $schema);
        $values = json_decode($result['text'], true);
        if (! is_array($values)) {
            throw new RuntimeException('AI returned an invalid form result.');
        }
        $budget->record('tenant_request_assistance', $result['model'], $result['input_tokens'], $result['output_tokens'], tenantId: $tenant->id);

        if ($isBookPurchase) {
            $enteredIsbn = $books->normalize((string) ($currentFields['isbn'] ?? ''));
            $rawIsbn = $isbnAbsent ? '' : ($books->isValid($enteredIsbn) ? $enteredIsbn : (string) ($values['isbn'] ?? ''));
            $isbn = $books->normalize($rawIsbn);
            $isbnValid = $books->isValid($isbn);
            $catalog = $isbnValid ? $books->lookup($isbn) : [];
            foreach (['isbn', 'title', 'author', 'publisher', 'publication_year', 'edition', 'pages', 'dimensions', 'language', 'listing_description'] as $key) {
                if (filled($catalog[$key] ?? null)) {
                    $values[$key] = (string) $catalog[$key];
                }
            }
            $values['isbn'] = $isbnAbsent ? '' : ($isbnValid ? $isbn : '');
            $suggestedPrice = max(0, (float) ($values['recommended_purchase_price'] ?? 0));
            $currency = strtoupper((string) ($values['price_currency'] ?? 'EUR')) ?: 'EUR';
            if (isset($catalog['reference_price'])) {
                $factor = match ((string) ($values['condition_grade'] ?? 'unknown')) {
                    'poor' => .05,
                    'fair' => .08,
                    'good' => .12,
                    'very_good' => .18,
                    default => .07,
                };
                $suggestedPrice = max(.50, round((float) $catalog['reference_price'] * $factor, 2));
                $currency = (string) ($catalog['reference_currency'] ?? $currency);
            }
            $stabilizedPrice = $pricing->stabilize(
                $tenant,
                $isbn,
                $suggestedPrice > 0 ? $suggestedPrice : .50,
                $currency,
                (string) ($values['condition_grade'] ?? 'unknown'),
            );
            $price = $pricing->format($stabilizedPrice['amount'], $stabilizedPrice['currency']);
            $masterComment = $this->bookAssessment(
                (string) ($values['master_comment'] ?? $values['condition'] ?? ''),
                $price,
                $locale,
            );
            $values['_ai_assessment'] = $masterComment;
            $values['_book_catalog'] = $catalog;
            $values['_recommended_purchase_price'] = $price;
            $values['_book_condition_grade'] = (string) ($values['condition_grade'] ?? 'unknown');
            $values['_book_price_anchored'] = $stabilizedPrice['anchored'];

            return response()->json([
                'values' => $values,
                'book' => [
                    'isbn_status' => $isbnAbsent ? 'absent' : (! $isbnValid ? 'unreadable' : ($catalog === [] ? 'not_found' : 'found')),
                    'isbn' => $values['isbn'],
                    'catalog' => $catalog,
                    'recommended_purchase_price' => $price,
                ],
            ]);
        }

        return response()->json(['values' => $values]);
    }

    public function createRequest(Request $request, ImageStorageService $images, BookCatalogService $books, BookPurchasePricingService $pricing, SmsService $sms): JsonResponse
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
            'name' => 'nullable|string|max:120', 'phone' => 'required|string|max:100', 'email' => 'nullable|email|max:190|required_if:preferred_channel,email',
            'preferred_channel' => 'nullable|in:phone,whatsapp,sms,email,push,vk', 'summary' => 'nullable|string|max:5000',
            'fields' => 'nullable', 'media_slots' => 'nullable', 'media' => 'required|array|min:1|max:12',
            'media.*' => 'file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime|max:262144',
            'isbn_absent' => 'nullable|boolean',
        ]);
        $fields = $this->jsonArray($data['fields'] ?? []);
        $slots = $this->jsonArray($data['media_slots'] ?? []);
        $locale = $this->locale($request, $tenant);
        $variationCode = (string) ($tenant->businessProfile?->variation?->code ?? '');
        $isBookPurchase = $variationCode === 'purchase.books';
        $isbnAbsent = $isBookPurchase && (bool) ($data['isbn_absent'] ?? false);
        if ($isBookPurchase && ! $isbnAbsent) {
            $isbn = $books->normalize((string) ($fields['isbn'] ?? ''));
            if (! $books->isValid($isbn)) {
                throw ValidationException::withMessages(['isbn' => 'ISBN could not be read. Enter a valid ISBN or select “No ISBN”.']);
            }
            $fields['isbn'] = $isbn;
        }
        $prefillAssessment = $isBookPurchase ? Str::limit(trim((string) ($fields['_ai_assessment'] ?? '')), 650) : '';
        $bookCatalog = $isBookPurchase && is_array($fields['_book_catalog'] ?? null) ? $fields['_book_catalog'] : [];
        $recommendedPurchasePrice = $isBookPurchase ? trim((string) ($fields['_recommended_purchase_price'] ?? '')) : '';
        $bookConditionGrade = $isBookPurchase ? trim((string) ($fields['_book_condition_grade'] ?? 'unknown')) : 'unknown';
        unset($fields['_ai_assessment'], $fields['_book_catalog'], $fields['_recommended_purchase_price'], $fields['_book_condition_grade'], $fields['_book_price_anchored']);
        if ($isBookPurchase) {
            $fields['isbn_absent'] = $isbnAbsent;
        }
        $mediaConfiguration = (array) data_get($this->configuration($tenant), 'media', []);
        $imageCount = collect($request->file('media', []))->filter(fn ($file) => str_starts_with((string) $file->getMimeType(), 'image/'))->count();
        $photosMin = max(1, (int) ($mediaConfiguration['photos_min'] ?? 1));
        $photosMax = max($photosMin, (int) ($mediaConfiguration['photos_max'] ?? 12));
        $requiredSlots = collect((array) ($mediaConfiguration['slots'] ?? []))->filter(fn (array $slot) => ($slot['required'] ?? false) === true)->pluck('key')->filter()->all();
        if ($isbnAbsent) {
            $photosMin = 1;
            $requiredSlots = array_values(array_diff($requiredSlots, ['book_isbn']));
        }
        $missingSlots = array_values(array_diff($requiredSlots, $slots));
        if ($imageCount < $photosMin || $imageCount > $photosMax || $missingSlots !== []) {
            throw ValidationException::withMessages([
                'media' => $missingSlots !== []
                    ? 'Required photos are missing: '.implode(', ', $missingSlots).'.'
                    : "Upload between {$photosMin} and {$photosMax} photos.",
            ]);
        }
        [$customer, $rawToken] = $this->customerAndToken($request, $tenant, $data, $locale);

        $tenantRequest = DB::transaction(function () use ($tenant, $customer, $data, $fields, $slots, $locale, $request, $images, $prefillAssessment, $bookCatalog, $recommendedPurchasePrice) {
            $contactSnapshot = Arr::only($data, ['name', 'phone', 'email', 'preferred_channel']);
            $contactSnapshot['business_variation_code'] = $tenant->businessProfile?->variation?->code;
            $appRequest = $tenant->appRequests()->create([
                'customer_id' => $customer->id,
                'request_template_id' => $tenant->businessProfile?->request_template_id,
                'number' => $this->number('R'), 'status' => 'new', 'summary' => $data['summary'] ?? null, 'locale' => $locale,
                'contact_snapshot' => $contactSnapshot,
            ]);
            foreach ($fields as $key => $value) {
                TenantRequestValue::create(['request_id' => $appRequest->id, 'field_key' => Str::limit((string) $key, 120, ''), 'value' => is_array($value) ? $value : ['value' => $value]]);
            }
            if ($prefillAssessment !== '') {
                TenantRequestValue::create([
                    'request_id' => $appRequest->id,
                    'field_key' => 'ai_condition_assessment',
                    'value' => [
                        'value' => $prefillAssessment,
                        'catalog' => $bookCatalog,
                        'recommended_purchase_price' => $recommendedPurchasePrice,
                        'condition_grade' => $bookConditionGrade,
                        'analyzed_at' => now()->toIso8601String(),
                        'status' => 'prefilled',
                    ],
                ]);
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

        if ($isBookPurchase && ! $isbnAbsent && $recommendedPurchasePrice !== '') {
            $pricing->remember(
                $tenantRequest,
                (string) ($fields['isbn'] ?? ''),
                $recommendedPurchasePrice,
                $bookConditionGrade,
                ['catalog_reference_price' => $bookCatalog['reference_price'] ?? null],
            );
        }

        AnalyzeTenantRequestMedia::dispatch($tenantRequest->id)->afterCommit();
        $this->notifyCustomerRequestReceived($tenant, $tenantRequest, $sms);
        $this->notifyMaster($tenant, 'new_request', '/app/requests', 'request-'.$tenantRequest->id, $tenantRequest);
        SendPlatformAdminNewRequestNotification::dispatch($tenant->id, $tenantRequest->id)->afterResponse();

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
        $exceptAppointmentId = null;
        if (filled($data['appointment_id'] ?? null)) {
            $customer = $this->requireCustomer($request, $tenant);
            $appointment = $tenant->appointments()
                ->with('service')
                ->where('customer_id', $customer->id)
                ->findOrFail($data['appointment_id']);
            abort_unless((int) $data['service_id'] === (int) $appointment->service_id, 422);
            abort_unless($appointment->service, 409);
            $service = $appointment->service;
            $exceptAppointmentId = $appointment->id;
        } else {
            $service = $tenant->services()
                ->where('active', true)
                ->where('booking_enabled', true)
                ->findOrFail($data['service_id']);
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

    public function createAppointment(Request $request, TenantCalendarService $calendar, TenantWebPushService $webPush): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
        if (! $this->enabled($tenant, 'booking_enabled', false)) {
            $this->apiError($request, $tenant, 'TENANT_APP_BOOKING_DISABLED', 'tenant_app.booking_disabled', 403);
        }
        $data = $request->validate([
            'service_id' => 'required|integer', 'starts_at' => 'required|date|after:now', 'name' => 'nullable|string|max:120',
            'phone' => 'required|string|max:100', 'email' => 'nullable|string|max:190', 'comment' => 'nullable|string|max:2000',
            'preferred_channel' => 'nullable|in:phone,whatsapp,viber,telegram,sms,email,push,vk',
            'resource_id' => 'nullable|integer',
            'service_mode' => 'nullable|in:workshop,on_site',
            'service_address' => 'nullable|required_if:service_mode,on_site|string|max:500',
            'push_subscription' => 'nullable|array',
            'push_subscription.endpoint' => 'required_with:push_subscription|url|max:2000',
            'push_subscription.keys.p256dh' => 'required_with:push_subscription|string|max:1000',
            'push_subscription.keys.auth' => 'required_with:push_subscription|string|max:500',
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
                'contact_snapshot' => Arr::only($data, ['name', 'phone', 'email', 'preferred_channel', 'service_mode', 'service_address']),
            ]);
        });

        $this->notifyMaster($tenant, 'new_appointment', '/app/calendar', 'appointment-'.$appointment->id);

        if (($data['preferred_channel'] ?? null) === 'push'
            && isset($data['push_subscription'])
            && $this->enabled($tenant, 'push_enabled', true)
            && $webPush->configured()) {
            $subscription = $data['push_subscription'];
            TenantPushSubscription::updateOrCreate(
                ['tenant_id' => $tenant->id, 'endpoint_hash' => hash('sha256', $subscription['endpoint'])],
                [
                    'customer_id' => $customer->id,
                    'endpoint' => $subscription['endpoint'],
                    'public_key' => $subscription['keys']['p256dh'],
                    'auth_token' => $subscription['keys']['auth'],
                    'locale' => $locale,
                ],
            );
            try {
                $webPush->sendToCustomer($customer, [
                    'title' => $tenant->name,
                    'body' => trans('tenant_app.customer_push.appointment_confirmed.body', [
                        'service' => $service->localized('name', $locale),
                        'date' => $start->locale($locale)->translatedFormat('j F, H:i'),
                    ], $locale),
                    'url' => '/activity',
                    'tag' => 'lookdo-appointment-'.$appointment->id,
                    'action' => trans('tenant_app.customer_push.open', locale: $locale),
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

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
        abort_unless($tenantAppointment->service, 409);
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
        $customer = $this->customerFromToken($request, $tenant);
        abort_unless($this->enabled($tenant, 'push_enabled', true), 403);
        $data = $request->validate(['endpoint' => 'required|url|max:2000', 'keys.p256dh' => 'required|string|max:1000', 'keys.auth' => 'required|string|max:500']);
        $endpointHash = hash('sha256', $data['endpoint']);
        TenantPushSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id, 'endpoint_hash' => $endpointHash],
            [
                'customer_id' => $customer?->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'locale' => $customer?->locale ?: $this->locale($request, $tenant),
            ],
        );

        return response()->json(['subscribed' => true]);
    }

    public function submitReview(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureAvailable($request, $tenant);
        $customer = $this->requireCustomer($request, $tenant);
        $data = $request->validate([
            'rating' => 'required|integer|between:1,5',
            'body' => 'required|string|max:3000',
            'author_name' => 'nullable|string|max:120',
        ]);
        $reviewedRequestIds = $tenant->reviews()->where('customer_id', $customer->id)->whereNotNull('request_id')->pluck('request_id');
        $tenantRequest = $tenant->appRequests()
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->when($reviewedRequestIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $reviewedRequestIds))
            ->latest('completed_at')
            ->latest('id')
            ->first();
        if (! $tenantRequest) {
            $this->apiError($request, $tenant, 'TENANT_APP_REVIEW_REQUIRES_COMPLETED_REQUEST', 'tenant_app.review_requires_completed_request', 403);
        }
        $review = $tenant->reviews()->create([
            'customer_id' => $customer->id,
            'request_id' => $tenantRequest->id,
            'rating' => $data['rating'],
            'body' => $data['body'],
            'author_name' => ($data['author_name'] ?? null) ?: $customer->name,
            'published' => false,
            'received_at' => now(),
        ]);

        return response()->json(['review' => $review, 'message' => trans('tenant_app.review_received', locale: $this->locale($request, $tenant))], 201);
    }

    private function reviewState(Tenant $tenant, ?TenantCustomer $customer): array
    {
        if (! $customer) {
            return ['can_submit' => false, 'submitted' => false, 'request_id' => null];
        }
        $reviewedRequestIds = $tenant->reviews()->where('customer_id', $customer->id)->whereNotNull('request_id')->pluck('request_id');
        $request = $tenant->appRequests()
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->when($reviewedRequestIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $reviewedRequestIds))
            ->latest('completed_at')
            ->latest('id')
            ->first();

        return [
            'can_submit' => (bool) $request,
            'submitted' => $reviewedRequestIds->isNotEmpty(),
            'request_id' => $request?->id,
        ];
    }

    private function notifyMaster(Tenant $tenant, string $event, string $url, string $tag, ?TenantRequest $tenantRequest = null): void
    {
        $tenant->loadMissing(['profile', 'users']);
        $preferences = (array) data_get($tenant->profile?->content, 'notifications', []);
        $locale = in_array($tenant->locale, ['de', 'en', 'ru', 'uk'], true) ? $tenant->locale : 'de';
        $title = trans("tenant_app.master_push.$event.title", locale: $locale);
        $body = trans("tenant_app.master_push.$event.body", locale: $locale);
        $requestContext = $tenantRequest
            ? trans('tenant_app.notifications.request_context', [
                'number' => $tenantRequest->number,
                'summary' => $this->notificationSummary($tenantRequest, 700, $locale),
            ], $locale)
            : null;
        if (($preferences['push'] ?? true) && $this->enabled($tenant, 'push_enabled', true)) {
            SendTenantMasterPush::dispatch($tenant->id, [
                'title' => $title, 'body' => $body, 'url' => $url, 'tag' => "lookdo-master-$tag",
                'action' => trans('tenant_app.master_push.open', locale: $locale),
            ])->afterResponse();
        }
        $emailEnabled = array_key_exists('email', $preferences)
            ? (bool) $preferences['email']
            : $event === 'new_request';
        if ($emailEnabled) {
            $emails = $tenant->users->where('is_active', true)->pluck('email')
                ->push($tenant->profile?->email)
                ->map(fn ($email) => trim((string) $email))
                ->filter()
                ->unique();
            foreach ($emails as $email) {
                try {
                    Mail::to($email)->send(new TenantNotificationMail(
                        $title,
                        collect([$title, $body, $requestContext, rtrim(config('app.url'), '/').$url])
                            ->filter()
                            ->implode("\n\n"),
                    ));
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
        if (($preferences['sms'] ?? false) && $event === 'new_request' && filled($tenant->profile?->phone)) {
            try {
                app(SmsService::class)->queueImportant($tenant, $tenant->profile->phone, $title.': '.$body, 'request_received', 'master-'.$tag);
            } catch (DomainException) {
                // SMS is an optional paid channel; email and in-app delivery remain active.
            }
        }
    }

    private function notifyCustomerRequestReceived(Tenant $tenant, TenantRequest $tenantRequest, SmsService $sms): void
    {
        $contact = (array) $tenantRequest->contact_snapshot;
        $locale = in_array($tenantRequest->locale, ['de', 'en', 'ru', 'uk'], true) ? $tenantRequest->locale : 'de';
        $summary = $this->notificationSummary($tenantRequest, 700, $locale);
        $url = $this->customerActivityUrl($tenant);
        $email = trim((string) ($contact['email'] ?? ''));

        if ($email !== '') {
            $subject = trans('tenant_app.notifications.customer_request_received_subject', [
                'number' => $tenantRequest->number,
            ], $locale);
            $body = trans('tenant_app.notifications.customer_request_received_body', [
                'business' => $tenant->name,
                'number' => $tenantRequest->number,
                'summary' => $summary,
                'url' => $url,
            ], $locale);
            try {
                Mail::to($email)->send(new TenantNotificationMail($subject, $body));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if (($contact['preferred_channel'] ?? null) === 'sms' && filled($contact['phone'] ?? null)) {
            $smsSummary = $this->notificationSummary($tenantRequest, 180, $locale);
            $message = trans('tenant_app.sms.request_received_with_context', [
                'business' => $tenant->name,
                'number' => $tenantRequest->number,
                'summary' => $smsSummary,
                'url' => $url,
            ], $locale);
            try {
                $sms->queueImportant(
                    $tenant,
                    (string) $contact['phone'],
                    $message,
                    'request_received',
                    'request-'.$tenantRequest->id.'-received',
                );
            } catch (DomainException) {
                // SMS is optional; a disabled channel must not suppress email confirmation.
            }
        }
    }

    private function notificationSummary(TenantRequest $tenantRequest, int $limit, string $locale): string
    {
        $summary = trim((string) $tenantRequest->summary);
        if ($summary === '') {
            $tenantRequest->loadMissing('values');
            $summary = $tenantRequest->values
                ->whereIn('field_key', ['title', 'author', 'isbn', 'comment', 'condition'])
                ->map(fn ($value) => trim((string) data_get($value->value, 'value', '')))
                ->filter()
                ->unique()
                ->take(3)
                ->implode(' · ');
        }

        return Str::limit($summary !== '' ? $summary : trans('tenant_app.notifications.no_summary', locale: $locale), $limit);
    }

    private function customerActivityUrl(Tenant $tenant): string
    {
        $tenant->loadMissing('primaryDomain');
        $domain = $tenant->primaryDomain?->domain ?: $tenant->slug.'.'.config('tenancy.platform_domain');

        return 'https://'.$domain.'/activity';
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
        $tenant->loadMissing(['businessProfile.template', 'businessProfile.variation', 'profile']);
        $presets = (array) config('tenant_apps.templates', []);
        $template = $tenant->businessProfile?->template;

        $configuration = $template
            ? $template->resolvedForVariation($tenant->businessProfile?->variation?->code, $presets)
            : (array) ($presets['general-services.general'] ?? []);
        $presetCode = (string) data_get($tenant->profile?->content, 'preset.code', '');
        $tenantPreset = (array) config('tenant_presets.presets.'.$presetCode, []);
        if (($tenantPreset['template'] ?? null) === ($template?->code ?: 'general-services.general')) {
            $presetConfiguration = (array) ($tenantPreset['configuration'] ?? []);
            $configuration = array_replace_recursive($configuration, $presetConfiguration);
            foreach (['locales', 'navigation', 'trust', 'starter_services', 'starter_portfolio'] as $listKey) {
                if (array_key_exists($listKey, $presetConfiguration)) {
                    $configuration[$listKey] = $presetConfiguration[$listKey];
                }
            }
        }

        $tenantConfiguration = (array) data_get($tenant->profile?->content, 'app_configuration', []);
        $customizationBaseTemplate = (string) data_get($tenant->profile?->content, 'ai_customization.base_template', '');
        $activeTemplateCode = $template?->code ?: 'general-services.general';
        if ($customizationBaseTemplate !== '' && $customizationBaseTemplate !== $activeTemplateCode) {
            $tenantConfiguration = [];
        }
        $configuration = array_replace_recursive($configuration, $tenantConfiguration);
        foreach (['fields', 'navigation', 'trust', 'starter_services', 'starter_portfolio', 'screens', 'actions', 'locales'] as $listKey) {
            if (array_key_exists($listKey, $tenantConfiguration)) {
                $configuration[$listKey] = $tenantConfiguration[$listKey];
            }
        }
        if (array_key_exists('slots', (array) ($tenantConfiguration['media'] ?? []))) {
            $configuration['media']['slots'] = $tenantConfiguration['media']['slots'];
        }

        return $configuration;
    }

    private function locale(Request $request, Tenant $tenant): string
    {
        $requested = trim((string) $request->header('X-Locale', ''));
        $locale = strtolower($requested !== '' ? $requested : $tenant->locale);
        $allowed = $this->enabledLocales($tenant, $this->configuration($tenant));

        return in_array($locale, $allowed, true) ? $locale : ($allowed[0] ?? $tenant->locale);
    }

    private function enabledLocales(Tenant $tenant, array $configuration): array
    {
        $configured = array_values(array_unique(array_intersect((array) ($configuration['locales'] ?? ['de', 'en', 'ru', 'uk']), ['de', 'en', 'ru', 'uk'])));
        $selected = (array) data_get($tenant->profile?->content, 'enabled_locales', $configured);
        $enabled = array_values(array_intersect($configured, $selected));

        return $enabled !== [] ? $enabled : [in_array($tenant->locale, $configured, true) ? $tenant->locale : ($configured[0] ?? 'de')];
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
                $tenant->services()->create(['name' => $service['name'], 'description' => $service['description'] ?? [], 'inclusions' => $service['inclusions'] ?? [], 'result' => $service['result'] ?? [], 'image_path' => $service['image'] ?? null, 'duration_minutes' => $service['duration'] ?? 60, 'booking_enabled' => true, 'active' => true, 'sort_order' => $index * 10]);
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
                $tenant->portfolioItems()->create([
                    'title' => $item['title'] ?? [],
                    'description' => $item['description'] ?? [],
                    'image_path' => $item['image'] ?? null,
                    'video_path' => $item['video'] ?? null,
                    'before_image_path' => $item['before_image'] ?? null,
                    'after_image_path' => $item['after_image'] ?? null,
                    'featured' => $item['featured'] ?? false,
                    'published' => true,
                    'sort_order' => $index * 10,
                ]);
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
        $timezone = $tenant->timezone ?: ($booking['timezone'] ?? 'Europe/Berlin');
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
            'description' => $this->localized($branding['description_translations'] ?? $tenant->business_description, $locale), 'logo' => $this->assetUrl($profile?->logo_path),
            'colors' => ['primary' => $profile?->primary_color ?: '#ff6b00', 'secondary' => $profile?->secondary_color ?: '#111318'],
            'contact' => [
                'name' => $profile?->contact_name,
                'phone' => $profile?->phone,
                'email' => $profile?->email,
                'street' => $profile?->street,
                'postal_code' => $profile?->postal_code,
                'city' => $profile?->city,
                'vk_url' => $branding['vk_url'] ?? null,
                'max_url' => $branding['max_url'] ?? null,
                'whatsapp_url' => $branding['whatsapp_url'] ?? null,
                'telegram_url' => $branding['telegram_url'] ?? null,
                'viber_url' => $branding['viber_url'] ?? null,
                'instagram_url' => $branding['instagram_url'] ?? null,
                'facebook_url' => $branding['facebook_url'] ?? null,
                'website_url' => $branding['website_url'] ?? null,
                'working_hours' => $branding['working_hours'] ?? null,
            ],
            'branding' => [
                'confirmed' => filled($branding['confirmed_at'] ?? null),
                'tagline' => $this->localized($branding['tagline_translations'] ?? ($branding['tagline'] ?? null), $locale),
                'hero_image' => $this->assetUrl($branding['hero_image_path'] ?? null),
                'horizontal_logo' => $this->assetUrl($branding['horizontal_logo_path'] ?? null),
                'service_modes' => array_values((array) ($branding['service_modes'] ?? [])),
            ],
        ];
    }

    private function templatePayload(Tenant $tenant, array $configuration, string $locale): array
    {
        $template = $tenant->businessProfile?->template;
        $templateCode = $template?->code ?: 'general-services.general';
        $media = (array) ($configuration['media'] ?? []);
        $slots = $this->localizedSlots((array) ($media['slots'] ?? $configuration['media_slots'] ?? []), $locale);
        $fields = $this->localizedFields((array) ($configuration['fields'] ?? []), $locale);
        $capabilities = array_replace(
            ['request' => true, 'portfolio' => true, 'reviews' => true],
            (array) ($configuration['capabilities'] ?? []),
        );
        if (str_starts_with($templateCode, 'purchase.')) {
            $capabilities['portfolio'] = false;
            $capabilities['reviews'] = false;
        }

        return [
            'id' => $template?->id, 'code' => $templateCode, 'name' => $template?->localized('name', $locale),
            'variation_code' => $tenant->businessProfile?->variation?->code,
            'engine' => $configuration['engine'] ?? 'request', 'layout' => $configuration['layout'] ?? 'general', 'navigation' => $this->normalizedNavigation($templateCode, $configuration),
            'theme' => $configuration['theme'] ?? [],
            'hero' => array_replace(
                (array) $this->localized($configuration['hero'] ?? [], $locale),
                filled(data_get($tenant->profile?->content, 'branding.hero_image_path'))
                    ? ['image' => $this->assetUrl(data_get($tenant->profile?->content, 'branding.hero_image_path'))]
                    : [],
            ), 'trust' => array_map(fn ($item) => $this->localized($item, $locale), $configuration['trust'] ?? []),
            'media_slots' => $slots, 'media' => ['photos_min' => (int) ($media['photos_min'] ?? 1), 'photos_max' => (int) ($media['photos_max'] ?? max(1, count($slots)))], 'video' => $media['video'] ?? $configuration['video'] ?? [], 'fields' => $fields,
            'ai_assistant' => $this->localized($configuration['ai_assistant'] ?? [], $locale),
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
            'locales' => $this->enabledLocales($tenant, $configuration),
            'capabilities' => $capabilities,
        ];
    }

    /** @return list<string> */
    private function normalizedNavigation(string $templateCode, array $configuration): array
    {
        if (str_starts_with($templateCode, 'purchase.')) {
            return ['home', 'action', 'activity'];
        }

        $configured = array_values((array) ($configuration['navigation'] ?? []));
        $content = in_array('services', $configured, true) ? 'services' : 'works';
        $primary = ($configuration['engine'] ?? 'request') === 'booking' ? 'book' : 'action';

        return ['home', $content, $primary, 'activity', 'reviews'];
    }

    private function localizedSlots(array $slots, string $locale): array
    {
        $labels = trans('tenant_app.media_slots', locale: $locale);
        $labels = is_array($labels) ? $labels : [];

        return array_map(function (array $slot) use ($labels, $locale): array {
            $label = $labels[$slot['key'] ?? ''] ?? $this->localized($slot['title'] ?? $slot['label'] ?? null, $locale);
            $slot['title'] = $label;
            $slot['label'] = $label;
            $slot['instruction'] = $this->localized($slot['instruction'] ?? '', $locale);

            return $slot;
        }, $slots);
    }

    private function localizedFields(array $fields, string $locale): array
    {
        $labels = trans('tenant_app.fields', locale: $locale);
        $options = trans('tenant_app.options', locale: $locale);
        $labels = is_array($labels) ? $labels : [];
        $options = is_array($options) ? $options : [];

        return array_map(function (array $field) use ($labels, $options, $locale): array {
            $field['label'] = $labels[$field['key'] ?? ''] ?? $this->localized($field['label'] ?? $field['key'], $locale);
            $field['placeholder'] = $this->localized($field['placeholder'] ?? null, $locale);
            if (isset($field['options'])) {
                $field['options'] = array_map(fn ($option) => $options[is_string($option) ? $option : ''] ?? $this->localized($option, $locale), $field['options']);
            }

            return $field;
        }, $fields);
    }

    private function servicePayload(TenantService $service, string $locale): array
    {
        return ['id' => $service->id, 'name' => $service->localized('name', $locale), 'description' => $service->localized('description', $locale), 'inclusions' => $service->localized('inclusions', $locale), 'result' => $service->localized('result', $locale), 'image' => $this->assetUrl($service->image_path), 'duration' => $service->duration_minutes, 'price' => $service->price, 'currency' => $service->currency];
    }

    private function bookAssessment(string $comment, string $price, string $locale): string
    {
        $comment = preg_replace([
            '/(?:Внутренняя рекомендация|Внутрішня рекомендація)[^.?!]*(?:[.?!]|$)/iu',
            '/(?:не оценка и не гарантия|не оцінка і не гарантія)[^.?!]*(?:[.?!]|$)/iu',
            '/[^.?!]*(?:Google Books|каталожн(?:ой|ої) запис)[^.?!]*(?:[.?!]|$)/iu',
            '/(?:Internal recommendation|Not an appraisal or guarantee)[^.?!]*(?:[.?!]|$)/iu',
            '/[^.?!]*Google Books[^.?!]*(?:[.?!]|$)/iu',
        ], ' ', $comment) ?? $comment;
        $comment = Str::limit(trim((string) preg_replace('/\s+/u', ' ', $comment)), 600);
        $priceLabel = match ($locale) {
            'ru' => 'Закупка',
            'uk' => 'Закупівля',
            'de' => 'Ankauf',
            default => 'Purchase',
        };

        return trim(implode("\n", array_filter([
            $comment !== '' ? '• '.$comment : null,
            '• '.$priceLabel.': '.$price,
        ])));
    }

    private function requestPayload(TenantRequest $request): array
    {
        $request->loadMissing(['media', 'messages']);

        return ['id' => $request->id, 'number' => $request->number, 'status' => $request->status, 'summary' => $request->summary, 'created_at' => $request->created_at?->toIso8601String(), 'media' => $request->media->map(fn ($item) => ['id' => $item->id, 'type' => $item->type, 'slot' => $item->slot_key, 'url' => Storage::disk('public')->url($item->storage_key)]), 'messages' => $request->messages->map(fn ($item) => $this->messagePayload($item))];
    }

    private function appointmentPayload(TenantAppointment $appointment, string $locale): array
    {
        return ['id' => $appointment->id, 'number' => $appointment->number, 'status' => $appointment->status, 'starts_at' => $appointment->starts_at?->toIso8601String(), 'ends_at' => $appointment->ends_at?->toIso8601String(), 'service_mode' => data_get($appointment->contact_snapshot, 'service_mode'), 'service_address' => data_get($appointment->contact_snapshot, 'service_address'), 'service' => $appointment->service ? $this->servicePayload($appointment->service, $locale) : null];
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

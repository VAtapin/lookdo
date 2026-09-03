<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantWorkspace;
use App\Models\Tenant;
use App\Models\TenantPortfolioItem;
use App\Models\TenantRequest;
use App\Models\TenantReview;
use App\Models\TenantSocialDraft;
use App\Services\EntitlementService;
use App\Services\ImageStorageService;
use App\Services\LocalizedContentTranslationService;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use App\Services\SocialPublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TenantContentController extends Controller
{
    use AuthorizesTenantWorkspace;

    public function index(Request $request, Tenant $tenant, EntitlementService $entitlements): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);

        return response()->json([
            'portfolio' => $tenant->portfolioItems()->with('service')->orderByDesc('created_at')->get()->each(fn ($item) => $this->urls($item)),
            'reviews' => $tenant->reviews()->with(['customer', 'request'])->latest()->get(),
            'social' => $tenant->socialDrafts()->with('portfolioItem')->latest()->get()->each(fn ($draft) => $this->socialUrl($draft)),
            'social_connections' => $tenant->socialConnections()->get([
                'id', 'provider', 'status', 'external_account_id', 'account_name',
                'expires_at', 'last_validated_at', 'last_error',
            ]),
            'social_providers' => collect(SocialPublishingService::DIRECT_PROVIDERS)->mapWithKeys(fn (string $provider) => [
                $provider => ['configured' => $this->socialProviderConfigured($tenant, $provider), 'custom' => $tenant->socialProviderConfigs()->where('provider', $provider)->exists()],
            ]),
            'entitlements' => $entitlements->all($tenant),
            'share_url' => 'https://'.$tenant->slug.'.'.config('tenancy.platform_domain'),
        ]);
    }

    public function savePortfolio(Request $request, Tenant $tenant, ImageStorageService $images, EntitlementService $entitlements, LocalizedContentTranslationService $translations, ?TenantPortfolioItem $item = null): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        if ($item) {
            abort_unless($item->tenant_id === $tenant->id, 404);
        }

        $data = $request->validate([
            'service_id' => ['nullable', 'integer', Rule::exists('tenant_services', 'id')->where('tenant_id', $tenant->id)],
            'source_locale' => ['nullable', Rule::in(['de', 'en', 'ru', 'uk'])],
            'title' => 'nullable|array',
            'description' => 'nullable|array',
            'featured' => 'required|boolean',
            'published' => 'required|boolean',
            'publication_confirmed' => 'nullable|boolean',
            'remove_video' => 'nullable|boolean',
            'image' => 'nullable|image|max:20480',
            'video' => 'nullable|file|mimetypes:video/mp4,video/webm,video/quicktime',
            'before' => 'nullable|image|max:20480',
            'after' => 'nullable|image|max:20480',
        ]);

        $sourceLocale = $data['source_locale'] ?? $tenant->locale;
        unset($data['source_locale']);
        $enabledLocales = (array) data_get($tenant->profile?->content, 'enabled_locales', [$sourceLocale]);
        $sourceChanged = ! $item
            || data_get($item->title, $sourceLocale, '') !== data_get($data, 'title.'.$sourceLocale, '')
            || data_get($item->description, $sourceLocale, '') !== data_get($data, 'description.'.$sourceLocale, '');
        $translationMissing = collect($enabledLocales)
            ->reject(fn ($locale) => $locale === $sourceLocale)
            ->contains(fn ($locale) => blank(data_get($data, 'title.'.$locale)));
        if (count(array_unique($enabledLocales)) > 1 && ($sourceChanged || $translationMissing) && filled(data_get($data, 'title.'.$sourceLocale))) {
            try {
                $localized = $translations->translateFields(
                    ['title' => (array) ($data['title'] ?? []), 'description' => (array) ($data['description'] ?? [])],
                    $sourceLocale,
                    $enabledLocales,
                    $request->user()?->id,
                    $tenant->id,
                    'portfolio_translation',
                );
                $data['title'] = $localized['title'];
                $data['description'] = $localized['description'];
            } catch (Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages(['translation' => 'Automatic translation failed: '.$exception->getMessage()]);
            }
        }

        if (($request->hasFile('before') || $request->hasFile('after')) && ! $this->enabled($entitlements, $tenant, 'before_after_enabled')) {
            abort(403, 'BEFORE_AFTER_NOT_INCLUDED');
        }
        if (($data['remove_video'] ?? false) && $item?->video_path) {
            Storage::disk('public')->delete($item->video_path);
            $data['video_path'] = null;
        }
        unset($data['remove_video']);
        if ($request->hasFile('video')) {
            abort_unless($this->enabled($entitlements, $tenant, 'video_enabled'), 403, 'VIDEO_NOT_INCLUDED');
            $maxVideoKb = max(1024, (int) $entitlements->get($tenant, 'video_max_mb', 100) * 1024);
            if ($request->file('video')->getSize() > $maxVideoKb * 1024) {
                throw ValidationException::withMessages(['video' => 'The video exceeds the plan size limit.']);
            }
            $old = $item?->video_path;
            $file = $request->file('video');
            $data['video_path'] = $file->storeAs(
                'tenant-app/'.$tenant->id.'/portfolio',
                Str::uuid().'.'.strtolower($file->getClientOriginalExtension()),
                'public',
            );
            if ($old && Storage::disk('public')->exists($old)) {
                Storage::disk('public')->delete($old);
            }
        }
        unset($data['video']);

        foreach (['image' => 'image_path', 'before' => 'before_image_path', 'after' => 'after_image_path'] as $field => $column) {
            if ($request->hasFile($field)) {
                $old = $item?->{$column};
                $data[$column] = $images->storeUploaded($request->file($field), 'tenant-app/'.$tenant->id.'/portfolio', 'public', 2048, 2048);
                if ($old && Storage::disk('public')->exists($old)) {
                    Storage::disk('public')->delete($old);
                }
            }
            unset($data[$field]);
        }

        if (($data['published'] ?? false) && ! ($data['publication_confirmed'] ?? false)) {
            abort(422, 'PUBLICATION_CONSENT_REQUIRED');
        }
        unset($data['publication_confirmed']);

        $item ? $item->update($data) : $item = $tenant->portfolioItems()->create($data);
        $this->urls($item);

        return response()->json(['item' => $item], $item->wasRecentlyCreated ? 201 : 200);
    }

    public function deletePortfolio(Request $request, Tenant $tenant, TenantPortfolioItem $item): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($item->tenant_id === $tenant->id, 404);

        foreach (['image_path', 'video_path', 'before_image_path', 'after_image_path'] as $field) {
            if ($item->{$field}) {
                Storage::disk('public')->delete($item->{$field});
            }
        }
        $item->delete();

        return response()->json(['deleted' => true]);
    }

    public function saveReview(Request $request, Tenant $tenant, ?TenantReview $review = null): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        if ($review) {
            abort_unless($review->tenant_id === $tenant->id, 404);
        }

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('tenant_customers', 'id')->where('tenant_id', $tenant->id)],
            'request_id' => ['nullable', 'integer', Rule::exists('tenant_requests', 'id')->where('tenant_id', $tenant->id)],
            'portfolio_item_id' => ['nullable', 'integer', Rule::exists('tenant_portfolio_items', 'id')->where('tenant_id', $tenant->id)],
            'rating' => 'required|integer|between:1,5',
            'author_name' => 'nullable|string|max:120',
            'body' => 'nullable|string|max:3000',
            'master_reply' => 'nullable|string|max:3000',
            'published' => 'required|boolean',
            'publication_confirmed' => 'nullable|boolean',
        ]);

        if ($data['published'] && ! ($data['publication_confirmed'] ?? false)) {
            abort(422, 'PUBLICATION_CONSENT_REQUIRED');
        }
        unset($data['publication_confirmed']);
        if (array_key_exists('master_reply', $data)) {
            $data['replied_at'] = filled($data['master_reply'])
                ? (($review && $review->master_reply === $data['master_reply']) ? ($review->replied_at ?: now()) : now())
                : null;
        }
        $data['received_at'] = $review?->received_at ?: now();
        $review ? $review->update($data) : $review = $tenant->reviews()->create($data);

        return response()->json(['review' => $review], $review->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteReview(Request $request, Tenant $tenant, TenantReview $review): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($review->tenant_id === $tenant->id, 404);
        $review->delete();

        return response()->json(['deleted' => true]);
    }

    public function saveSocial(Request $request, Tenant $tenant, EntitlementService $entitlements, ImageStorageService $images, ?TenantSocialDraft $draft = null): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($this->enabled($entitlements, $tenant, 'social_content_enabled'), 403, 'SOCIAL_CONTENT_NOT_INCLUDED');
        if ($draft) {
            abort_unless($draft->tenant_id === $tenant->id, 404);
        }

        $data = $request->validate([
            'portfolio_item_id' => ['nullable', 'integer', Rule::exists('tenant_portfolio_items', 'id')->where('tenant_id', $tenant->id)],
            'format' => 'required|in:story,feed,status',
            'channel' => 'required|in:share,instagram,whatsapp,telegram,viber,vk,facebook,linkedin,x',
            'locale' => 'required|in:de,en,ru,uk',
            'caption' => 'nullable|string|max:5000',
            'image_path' => 'nullable|string|max:500',
            'image' => 'nullable|image|max:20480',
            'booking_url' => 'nullable|url|max:500',
            'status' => 'required|in:draft,ready,published',
        ]);

        if ($request->hasFile('image')) {
            $old = $draft?->image_path;
            $data['image_path'] = $images->storeUploaded($request->file('image'), 'tenant-app/'.$tenant->id.'/social', 'public', 2048, 2048);
            if ($old && str_starts_with($old, 'tenant-app/'.$tenant->id.'/social/') && Storage::disk('public')->exists($old)) {
                Storage::disk('public')->delete($old);
            }
        } elseif (filled($data['image_path'] ?? null)) {
            $allowedPaths = $tenant->portfolioItems()->get(['image_path', 'before_image_path', 'after_image_path'])
                ->flatMap(fn ($item) => [$item->image_path, $item->before_image_path, $item->after_image_path])
                ->filter()
                ->values();
            abort_unless($allowedPaths->contains($data['image_path']) || $data['image_path'] === $draft?->image_path, 422, 'INVALID_SOCIAL_IMAGE');
        }
        unset($data['image']);

        $data['published_at'] = $data['status'] === 'published' ? now() : null;
        $draft ? $draft->update($data) : $draft = $tenant->socialDrafts()->create($data);
        $this->socialUrl($draft);

        return response()->json(['draft' => $draft], $draft->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteSocial(Request $request, Tenant $tenant, TenantSocialDraft $draft): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($draft->tenant_id === $tenant->id, 404);
        if ($draft->image_path && str_starts_with($draft->image_path, 'tenant-app/'.$tenant->id.'/social/')) {
            Storage::disk('public')->delete($draft->image_path);
        }
        $draft->delete();

        return response()->json(['deleted' => true]);
    }

    public function ai(Request $request, Tenant $tenant, OpenAiService $openAi, OpenAiBudgetService $budget, EntitlementService $entitlements): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($this->enabled($entitlements, $tenant, 'ai_communication_enabled'), 403, 'AI_NOT_INCLUDED');

        $data = $request->validate([
            'task' => 'required|in:reply,reminder,repeat_visit,vacancy,social,translate',
            'locale' => 'required|in:de,en,ru,uk',
            'context' => 'nullable|string|max:8000',
            'request_id' => 'nullable|integer',
            'internal_note' => 'nullable|string|max:5000',
        ]);

        $isRequestReply = $data['task'] === 'reply' && filled($data['request_id'] ?? null);
        if ($isRequestReply) {
            $tenantRequest = $tenant->appRequests()
                ->with(['customer', 'values', 'messages.senderUser', 'template'])
                ->findOrFail($data['request_id']);
            $data['context'] = $this->replyContext(
                $tenant,
                $tenantRequest,
                array_key_exists('internal_note', $data) ? $data['internal_note'] : $tenantRequest->internal_note,
            );
        }
        if (blank($data['context'] ?? null)) {
            throw ValidationException::withMessages(['context' => 'Context is required.']);
        }

        if ($data['task'] === 'repeat_visit') {
            abort_unless($this->enabled($entitlements, $tenant, 'repeat_visit_enabled'), 403, 'REPEAT_VISITS_NOT_INCLUDED');
        }
        if ($data['task'] === 'vacancy') {
            abort_unless($this->enabled($entitlements, $tenant, 'vacancy_fill_enabled'), 403, 'VACANCY_FILL_NOT_INCLUDED');
        }
        if ($data['task'] === 'social') {
            abort_unless($this->enabled($entitlements, $tenant, 'social_content_enabled'), 403, 'SOCIAL_CONTENT_NOT_INCLUDED');
        }

        $budget->ensureAvailable($request->user()->id);
        $language = ['de' => 'German', 'en' => 'English', 'ru' => 'Russian', 'uk' => 'Ukrainian'][$data['locale']];
        $instructions = $isRequestReply
            ? 'You assist a business owner replying to a customer. Read the complete application data, AI assessment, internal note and chronological conversation before drafting anything. Identify the latest actual customer question or request and answer it directly. System notices are background information, never customer questions. Never repeat, paraphrase or resend information the business already sent in the conversation. If the latest message is from the business and the customer has not replied, propose only a useful new next step when one is justified by the available facts. Do not write another acknowledgement that the request was received. Use the internal note as private guidance but never mention that it is an internal note. Write only the final message in '.$language.', normally 1–4 short sentences. Be concrete, honest and friendly. Never invent prices, availability, shipping details, promises, results or customer facts. Do not use JSON, quotes, markdown, headings or commentary.'
            : 'You assist a service-business owner. Write only the final text in '.$language.'. Be concise, honest and friendly. Never invent prices, availability, promises, results or customer facts. Do not wrap the answer in JSON, quotes or markdown. Task: '.$data['task'];

        try {
            $result = $openAi->text($instructions, $data['context']);
        } catch (RuntimeException $exception) {
            Log::warning('Tenant AI request failed', [
                'tenant_id' => $tenant->id,
                'user_id' => $request->user()->id,
                'task' => $data['task'],
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => trans('tenant_app.ai_temporarily_unavailable', locale: $data['locale']),
                'code' => 'AI_TEMPORARILY_UNAVAILABLE',
            ], 503);
        }

        $text = trim($result['text']);
        $budget->record('tenant_'.$data['task'], $result['model'], $result['input_tokens'], $result['output_tokens'], $request->user()->id, null, $tenant->id);

        return response()->json(['text' => $text]);
    }

    private function replyContext(Tenant $tenant, TenantRequest $tenantRequest, ?string $internalNote): string
    {
        $messages = $tenantRequest->messages
            ->sortBy(fn ($message) => sprintf('%s-%010d', $message->created_at?->toIso8601String() ?? '', $message->id))
            ->values()
            ->map(fn ($message) => [
                'role' => match ($message->sender_type) {
                    'customer' => 'customer',
                    'master' => 'business',
                    default => 'system_notice',
                },
                'text' => $message->body,
                'sent_at' => $message->created_at?->toIso8601String(),
            ])
            ->all();
        $lastCustomerMessage = collect($messages)->last(fn (array $message) => $message['role'] === 'customer');
        $fields = $tenantRequest->values
            ->reject(fn ($value) => in_array($value->field_key, ['ai_analysis_status', 'ai_analysis_error'], true))
            ->mapWithKeys(fn ($value) => [$value->field_key => $value->value])
            ->all();
        $context = [
            'business' => [
                'name' => $tenant->name,
                'description' => $tenant->business_description,
            ],
            'application' => [
                'number' => $tenantRequest->number,
                'status' => $tenantRequest->status,
                'summary' => $tenantRequest->summary,
                'locale' => $tenantRequest->locale,
                'customer' => [
                    'name' => $tenantRequest->customer?->name,
                    'preferred_channel' => $tenantRequest->customer?->preferred_channel
                        ?: data_get($tenantRequest->contact_snapshot, 'preferred_channel'),
                ],
                'fields_and_ai_assessment' => $fields,
            ],
            'private_internal_note' => filled($internalNote) ? trim((string) $internalNote) : null,
            'latest_customer_message' => $lastCustomerMessage,
            'full_conversation_oldest_to_newest' => $messages,
        ];

        return json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    private function enabled(EntitlementService $entitlements, Tenant $tenant, string $key): bool
    {
        return filter_var($entitlements->get($tenant, $key, false), FILTER_VALIDATE_BOOL);
    }

    private function urls(TenantPortfolioItem $item): void
    {
        foreach (['image_path', 'video_path', 'before_image_path', 'after_image_path'] as $field) {
            if ($item->{$field}) {
                $item->setAttribute(str_replace('_path', '_url', $field), $this->mediaUrl($item->{$field}));
            }
        }
    }

    private function socialUrl(TenantSocialDraft $draft): void
    {
        if ($draft->image_path) {
            $draft->setAttribute('image_url', $this->mediaUrl($draft->image_path));
        }
    }

    private function mediaUrl(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function socialProviderConfigured(Tenant $tenant, string $provider): bool
    {
        $credentials = (array) $tenant->socialProviderConfigs()->where('provider', $provider)->first()?->credentials;
        if ($credentials !== []) {
            return $provider === 'telegram'
                ? filled($credentials['bot_token'] ?? null)
                : filled($credentials['client_id'] ?? null) && filled($credentials['client_secret'] ?? null);
        }

        return false;
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantWorkspace;
use App\Models\Tenant;
use App\Models\TenantPortfolioItem;
use App\Models\TenantReview;
use App\Models\TenantSocialDraft;
use App\Services\EntitlementService;
use App\Services\ImageStorageService;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TenantContentController extends Controller
{
    use AuthorizesTenantWorkspace;

    public function index(Request $request, Tenant $tenant, EntitlementService $entitlements): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);

        return response()->json([
            'portfolio' => $tenant->portfolioItems()->with('service')->orderByDesc('created_at')->get()->each(fn ($item) => $this->urls($item)),
            'reviews' => $tenant->reviews()->with(['customer', 'request'])->latest()->get(),
            'social' => $tenant->socialDrafts()->with('portfolioItem')->latest()->get(),
            'entitlements' => $entitlements->all($tenant),
        ]);
    }

    public function savePortfolio(Request $request, Tenant $tenant, ImageStorageService $images, EntitlementService $entitlements, ?TenantPortfolioItem $item = null): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        if ($item) {
            abort_unless($item->tenant_id === $tenant->id, 404);
        }

        $data = $request->validate([
            'service_id' => ['nullable', 'integer', Rule::exists('tenant_services', 'id')->where('tenant_id', $tenant->id)],
            'title' => 'nullable|array',
            'description' => 'nullable|array',
            'featured' => 'required|boolean',
            'published' => 'required|boolean',
            'publication_confirmed' => 'nullable|boolean',
            'image' => 'nullable|image|max:20480',
            'before' => 'nullable|image|max:20480',
            'after' => 'nullable|image|max:20480',
        ]);

        if (($request->hasFile('before') || $request->hasFile('after')) && ! $this->enabled($entitlements, $tenant, 'before_after_enabled')) {
            abort(403, 'BEFORE_AFTER_NOT_INCLUDED');
        }

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

        foreach (['image_path', 'before_image_path', 'after_image_path'] as $field) {
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
            'published' => 'required|boolean',
            'publication_confirmed' => 'nullable|boolean',
        ]);

        if ($data['published'] && ! ($data['publication_confirmed'] ?? false)) {
            abort(422, 'PUBLICATION_CONSENT_REQUIRED');
        }
        unset($data['publication_confirmed']);
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

    public function saveSocial(Request $request, Tenant $tenant, EntitlementService $entitlements, ?TenantSocialDraft $draft = null): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($this->enabled($entitlements, $tenant, 'social_content_enabled'), 403, 'SOCIAL_CONTENT_NOT_INCLUDED');
        if ($draft) {
            abort_unless($draft->tenant_id === $tenant->id, 404);
        }

        $data = $request->validate([
            'portfolio_item_id' => ['nullable', 'integer', Rule::exists('tenant_portfolio_items', 'id')->where('tenant_id', $tenant->id)],
            'format' => 'required|in:story,feed,status',
            'channel' => 'required|in:share,instagram,whatsapp,telegram,viber,vk',
            'locale' => 'required|in:de,en,ru,uk',
            'caption' => 'nullable|string|max:5000',
            'image_path' => 'nullable|string|max:500',
            'booking_url' => 'nullable|url|max:500',
            'status' => 'required|in:draft,ready,published',
        ]);

        $data['published_at'] = $data['status'] === 'published' ? now() : null;
        $draft ? $draft->update($data) : $draft = $tenant->socialDrafts()->create($data);

        return response()->json(['draft' => $draft], $draft->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteSocial(Request $request, Tenant $tenant, TenantSocialDraft $draft): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($draft->tenant_id === $tenant->id, 404);
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
            'context' => 'required|string|max:8000',
        ]);

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
        $instructions = 'You assist a service-business owner. Return JSON with one key "text". Write in locale '.$data['locale'].'. Be concise, honest and friendly. Never invent prices, availability, promises, results or customer facts. Task: '.$data['task'];
        $result = $openAi->text($instructions, $data['context']);
        $decoded = json_decode($result['text'], true);
        $text = is_array($decoded) ? ($decoded['text'] ?? $result['text']) : $result['text'];
        $budget->record('tenant_'.$data['task'], $result['model'], $result['input_tokens'], $result['output_tokens'], $request->user()->id, null, $tenant->id);

        return response()->json(['text' => $text]);
    }

    private function enabled(EntitlementService $entitlements, Tenant $tenant, string $key): bool
    {
        return filter_var($entitlements->get($tenant, $key, false), FILTER_VALIDATE_BOOL);
    }

    private function urls(TenantPortfolioItem $item): void
    {
        foreach (['image_path', 'before_image_path', 'after_image_path'] as $field) {
            if ($item->{$field}) {
                $item->setAttribute(str_replace('_path', '_url', $field), Storage::disk('public')->url($item->{$field}));
            }
        }
    }
}

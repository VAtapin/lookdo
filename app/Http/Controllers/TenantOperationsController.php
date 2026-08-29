<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantWorkspace;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\TenantResource;
use App\Models\TenantSegment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantOperationsController extends Controller
{
    use AuthorizesTenantWorkspace;

    public function resources(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $this->ensureResources($tenant);

        return response()->json([
            'resources' => $tenant->resources()->with('user:id,name,email')->withCount(['appointments', 'blocks'])->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function saveResource(Request $request, Tenant $tenant, ?TenantResource $resource = null): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        if ($resource) {
            abort_unless($resource->tenant_id === $tenant->id, 404);
        }
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('tenant_users', 'user_id')->where('tenant_id', $tenant->id)],
            'name' => 'required|string|max:120',
            'kind' => 'required|in:staff,room,equipment',
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'active' => 'required|boolean',
            'sort_order' => 'nullable|integer|between:0,10000',
        ]);
        $data['sort_order'] ??= 0;
        $resource ? $resource->update($data) : $resource = $tenant->resources()->create($data);

        return response()->json(['resource' => $resource->fresh('user:id,name,email')], $resource->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteResource(Request $request, Tenant $tenant, TenantResource $resource): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($resource->tenant_id === $tenant->id, 404);
        abort_if($resource->appointments()->exists() || $resource->blocks()->exists(), 422, 'RESOURCE_IS_IN_USE');
        $resource->delete();

        return response()->json(['deleted' => true]);
    }

    public function segments(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);

        return response()->json([
            'segments' => $tenant->segments()->withCount('customers')->orderBy('name')->get(),
            'smart_segments' => $this->smartSegmentCounts($tenant),
        ]);
    }

    public function saveSegment(Request $request, Tenant $tenant, ?TenantSegment $segment = null): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        if ($segment) {
            abort_unless($segment->tenant_id === $tenant->id, 404);
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'rules' => 'nullable|array',
            'active' => 'required|boolean',
        ]);
        $segment ? $segment->update($data) : $segment = $tenant->segments()->create($data);

        return response()->json(['segment' => $segment->fresh()->loadCount('customers')], $segment->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteSegment(Request $request, Tenant $tenant, TenantSegment $segment): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($segment->tenant_id === $tenant->id, 404);
        $segment->customers()->detach();
        $segment->delete();

        return response()->json(['deleted' => true]);
    }

    public function syncCustomerSegments(Request $request, Tenant $tenant, TenantCustomer $customer): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($customer->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'segment_ids' => 'present|array',
            'segment_ids.*' => ['integer', Rule::exists('tenant_segments', 'id')->where('tenant_id', $tenant->id)],
        ]);
        $customer->segments()->sync($data['segment_ids']);

        return response()->json(['customer' => $customer->fresh('segments')]);
    }

    public function vacancyCandidates(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $data = $request->validate([
            'service_id' => ['nullable', 'integer', Rule::exists('tenant_services', 'id')->where('tenant_id', $tenant->id)],
            'limit' => 'nullable|integer|between:1,200',
            'inactive_days' => 'nullable|integer|between:1,3650',
        ]);
        $inactiveBefore = CarbonImmutable::now()->subDays((int) ($data['inactive_days'] ?? 60));
        $customers = $tenant->customers()->with('segments')
            ->where(function ($query) {
                $query->whereNotNull('marketing_consent_at')->orWhereNotNull('service_consent_at');
            })
            ->when($data['service_id'] ?? null, function ($query, $serviceId) {
                $query->whereHas('appointments', fn ($appointments) => $appointments->where('service_id', $serviceId));
            })
            ->where(function ($query) use ($inactiveBefore) {
                $query->whereNull('last_activity_at')->orWhere('last_activity_at', '<=', $inactiveBefore);
            })
            ->orderBy('last_activity_at')->limit((int) ($data['limit'] ?? 50))->get();

        return response()->json(['customers' => $customers]);
    }

    private function ensureResources(Tenant $tenant): void
    {
        if ($tenant->resources()->exists()) {
            return;
        }
        $tenant->users()->get()->each(function ($user, int $index) use ($tenant): void {
            $tenant->resources()->create([
                'user_id' => $user->id,
                'name' => $user->name,
                'kind' => 'staff',
                'color' => ['#ff6b00', '#d9a441', '#3b82f6', '#10b981'][$index % 4],
                'active' => (bool) $user->is_active,
                'sort_order' => $index,
            ]);
        });
    }

    private function smartSegmentCounts(Tenant $tenant): array
    {
        $now = CarbonImmutable::now();

        return [
            ['code' => 'new', 'count' => $tenant->customers()->where('created_at', '>=', $now->subDays(30))->count()],
            ['code' => 'inactive', 'count' => $tenant->customers()->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<=', $now->subDays(90)))->count()],
            ['code' => 'marketing', 'count' => $tenant->customers()->whereNotNull('marketing_consent_at')->count()],
            ['code' => 'repeat_due', 'count' => $tenant->customers()->whereHas('appointments', fn ($q) => $q->where('status', 'completed')->where('ends_at', '<=', $now->subDays(30)))->count()],
        ];
    }
}

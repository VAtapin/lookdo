<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantWorkspace;
use App\Models\Tenant;
use App\Models\TenantAppointment;
use App\Models\TenantCustomer;
use App\Models\TenantPushSubscription;
use App\Models\TenantRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CustomerMergeService;
use App\Services\EntitlementService;
use App\Services\SmsService;
use App\Services\TenantCalendarService;
use App\Services\TenantWebPushService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantWorkspaceController extends Controller
{
    use AuthorizesTenantWorkspace;

    public function bootstrap(Request $request, Tenant $tenant, TenantCalendarService $calendar, EntitlementService $entitlements, TenantWebPushService $webPush): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $calendar->ensureWorkingHours($tenant);
        $today = now($tenant->timezone ?: 'Europe/Berlin')->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $appointments = $tenant->appointments()->with(['customer', 'service'])->whereBetween('starts_at', [$today, $tomorrow])->orderBy('starts_at')->get();
        $requests = $tenant->appRequests()
            ->with(['customer', 'media'])
            ->where('status', 'new')
            ->latest()
            ->get();
        $pendingAppointments = $tenant->appointments()
            ->with(['customer', 'service'])
            ->where('status', 'pending')
            ->latest()
            ->get();
        $newItems = $requests->map(fn ($item) => $this->requestItem($item))
            ->concat($pendingAppointments->map(fn ($item) => $this->appointment($item)));
        $unread = $tenant->messages()->where('sender_type', 'customer')->whereNull('read_at')->count();
        $repeat = $tenant->appointments()
            ->with('service:id,repeat_interval_days')
            ->where('status', 'completed')
            ->whereNotNull('customer_id')
            ->latest('starts_at')
            ->get(['id', 'customer_id', 'service_id', 'starts_at'])
            ->unique('customer_id')
            ->filter(function ($appointment): bool {
                $days = (int) ($appointment->service?->repeat_interval_days ?? 0);

                return $days > 0 && $appointment->starts_at?->copy()->addDays($days)->isPast();
            })
            ->count();

        return response()->json([
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'locale' => $tenant->locale, 'platform_url' => 'https://'.$tenant->slug.'.'.config('tenancy.platform_domain')],
            'today' => ['date' => $today->toDateString(), 'appointments' => $appointments->map(fn ($a) => $this->appointment($a)), 'requests' => $newItems->values(), 'unread' => $unread, 'free_slots' => $this->freeSlots($tenant, $calendar, $today->toDateString()), 'repeat_candidates' => $repeat, 'unpublished_works' => $tenant->portfolioItems()->where('published', false)->count()],
            'counts' => ['requests' => $tenant->appRequests()->count(), 'new_requests' => $tenant->appRequests()->where('status', 'new')->count() + $tenant->appointments()->where('status', 'pending')->count(), 'customers' => $tenant->customers()->count(), 'messages' => $unread, 'appointments' => $tenant->appointments()->where('starts_at', '>=', now())->whereNotIn('status', ['cancelled'])->count()],
            'services' => $tenant->services()->whereNull('archived_at')->orderBy('sort_order')->get(), 'working_hours' => $tenant->workingHours()->orderBy('weekday')->get(),
            'access' => ['trial' => (bool) $tenant->currentSubscription?->isTrialActive(), 'entitlements' => $entitlements->all($tenant)],
            'push' => ['enabled' => $webPush->configured() && (string) $entitlements->get($tenant, 'push_enabled', '1') === '1', 'public_key' => $webPush->configured() ? (string) config('services.webpush.vapid_public_key', '') : ''],
        ]);
    }

    public function requests(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $q = $tenant->appRequests()->with(['customer', 'media', 'values', 'messages.senderUser', 'template']);
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $q->where(fn ($x) => $x->where('number', 'like', $term)->orWhere('summary', 'like', $term)->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term)));
        }

        $appointments = $tenant->appointments()->with(['customer', 'service'])->latest()->limit(100);
        if ($request->filled('status')) {
            $appointments->where('status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $appointments->where(fn ($x) => $x->where('number', 'like', $term)->orWhere('comment', 'like', $term)->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term)));
        }

        return response()->json([
            'items' => $q->latest()->paginate(30)->through(fn ($r) => $this->requestItem($r, true)),
            'appointments' => $appointments->get()->map(fn ($appointment) => $this->appointment($appointment) + ['kind' => 'appointment']),
        ]);
    }

    public function updateAppointment(Request $request, Tenant $tenant, TenantAppointment $tenantAppointment, AuditService $audit): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($tenantAppointment->tenant_id === $tenant->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'confirmed', 'completed', 'cancelled', 'no_show'])]]);
        $before = $tenantAppointment->only(['status']);
        $tenantAppointment->update($data);
        $audit->log('workspace.appointment.updated', $tenantAppointment, $before, $tenantAppointment->fresh()->only(['status']), $tenant->id);

        return response()->json(['appointment' => $this->appointment($tenantAppointment->fresh(['customer', 'service'])) + ['kind' => 'appointment']]);
    }

    public function updateRequest(Request $request, Tenant $tenant, TenantRequest $tenantRequest, AuditService $audit): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($tenantRequest->tenant_id === $tenant->id, 404);
        $data = $request->validate(['status' => 'sometimes|in:new,viewed,in_progress,waiting,completed,cancelled', 'internal_note' => 'nullable|string|max:5000']);
        if (($data['status'] ?? null) === 'completed') {
            $data['completed_at'] = now();
        }
        $before = $tenantRequest->only(['status', 'internal_note', 'completed_at']);
        $tenantRequest->update($data);
        $audit->log('workspace.request.updated', $tenantRequest, $before, $tenantRequest->fresh()->only(['status', 'internal_note', 'completed_at']), $tenant->id);

        return response()->json(['request' => $this->requestItem($tenantRequest->fresh(['customer', 'media', 'values', 'messages.senderUser', 'template']), true)]);
    }

    public function markRequestRead(Request $request, Tenant $tenant, TenantRequest $tenantRequest): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($tenantRequest->tenant_id === $tenant->id, 404);
        $readAt = now();
        $marked = $tenantRequest->messages()->where('sender_type', 'customer')->whereNull('read_at')->update(['read_at' => $readAt]);
        $unread = $tenant->messages()->where('sender_type', 'customer')->whereNull('read_at')->count();

        return response()->json(['marked' => $marked, 'unread' => $unread, 'read_at' => $readAt->toIso8601String()]);
    }

    public function reply(Request $request, Tenant $tenant, TenantRequest $tenantRequest, SmsService $sms, AuditService $audit): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($tenantRequest->tenant_id === $tenant->id, 404);
        $data = $request->validate(['body' => 'required|string|max:5000', 'event' => 'nullable|in:master_replied,work_ready']);
        $message = $tenantRequest->messages()->create(['tenant_id' => $tenant->id, 'customer_id' => $tenantRequest->customer_id, 'sender_type' => 'master', 'sender_user_id' => $request->user()->id, 'body' => $data['body']]);
        $tenantRequest->messages()->where('sender_type', 'customer')->whereNull('read_at')->update(['read_at' => now()]);
        $customer = $tenantRequest->customer;
        $delivery = ['push' => null, 'sms' => null];
        if ($customer) {
            $delivery['push'] = ['status' => 'queued'];
            if (filled($customer->phone)) {
                try {
                    $queued = $sms->queueImportant($tenant, $customer->phone, $data['body'], $data['event'] ?? 'master_replied', 'request-'.$tenantRequest->id.'-message-'.$message->id);
                    $delivery['sms'] = ['status' => $queued->status];
                } catch (DomainException $e) {
                    $delivery['sms'] = ['skipped' => $e->getMessage()];
                }
            }
        }
        $audit->log('workspace.request.replied', $message, null, [
            'request_id' => $tenantRequest->id,
            'request_number' => $tenantRequest->number,
            'event' => $data['event'] ?? 'master_replied',
            'delivery' => $delivery,
        ], $tenant->id);

        return response()->json(['message' => $message, 'delivery' => $delivery], 201);
    }

    public function conversations(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $items = $tenant->appRequests()->whereHas('messages')->with(['customer', 'messages' => fn ($q) => $q->latest()])->latest('updated_at')->get()->map(fn ($r) => [
            'request' => $this->requestItem($r, true), 'customer' => $r->customer, 'last_message' => $r->messages->first(), 'unread' => $r->messages->where('sender_type', 'customer')->whereNull('read_at')->count(),
        ]);

        return response()->json(['items' => $items]);
    }

    public function customers(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $q = $tenant->customers()->withCount(['requests', 'appointments'])->with(['possibleDuplicate', 'segments']);
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $q->where(fn ($x) => $x->where('name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('email', 'like', $term));
        }

        return response()->json(['items' => $q->latest('last_activity_at')->paginate(40)]);
    }

    public function customer(Request $request, Tenant $tenant, TenantCustomer $customer): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($customer->tenant_id === $tenant->id, 404);

        return response()->json([
            'customer' => $customer->load(['possibleDuplicate', 'segments']),
            'requests' => $customer->requests()->with('media')->latest()->limit(50)->get()->map(fn (TenantRequest $tenantRequest) => $this->requestItem($tenantRequest)),
            'appointments' => $customer->appointments()->with('service')->latest('starts_at')->limit(50)->get()->map(fn ($appointment) => $this->appointment($appointment)),
            'messages' => $customer->messages()->latest()->limit(50)->get()->reverse()->values(),
        ]);
    }

    public function updateCustomer(Request $request, Tenant $tenant, TenantCustomer $customer, AuditService $audit): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($customer->tenant_id === $tenant->id, 404);
        $data = $request->validate(['name' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:50', 'email' => 'nullable|email|max:190', 'preferred_channel' => 'nullable|in:phone,whatsapp,sms,email,push,vk', 'notes' => 'nullable|string|max:5000', 'tags' => 'nullable|array', 'service_consent' => 'nullable|boolean', 'marketing_consent' => 'nullable|boolean', 'publication_consent' => 'nullable|boolean']);
        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = preg_replace('/\D+/', '', (string) $data['phone']);
        }
        if (array_key_exists('marketing_consent', $data)) {
            $data['marketing_consent_at'] = $data['marketing_consent'] ? now() : null;
            unset($data['marketing_consent']);
        }
        if (array_key_exists('service_consent', $data)) {
            $data['service_consent_at'] = $data['service_consent'] ? now() : null;
            unset($data['service_consent']);
        }
        if (array_key_exists('publication_consent', $data)) {
            $data['publication_consent_at'] = $data['publication_consent'] ? now() : null;
            unset($data['publication_consent']);
        }
        $before = $customer->only(array_keys($data));
        $customer->update($data);
        $audit->log('workspace.customer.updated', $customer, $before, $customer->fresh()->only(array_keys($data)), $tenant->id);

        return response()->json(['customer' => $customer->fresh(['possibleDuplicate', 'segments'])]);
    }

    public function mergeCustomer(Request $request, Tenant $tenant, TenantCustomer $customer, CustomerMergeService $merger, AuditService $audit): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($customer->tenant_id === $tenant->id, 404);
        $data = $request->validate(['source_id' => 'required|integer|different:target_id']);
        $source = $tenant->customers()->findOrFail($data['source_id']);
        $before = ['target' => $customer->only(['id', 'name', 'phone', 'email']), 'source' => $source->only(['id', 'name', 'phone', 'email'])];
        $customer = $merger->merge($customer, $source);
        $audit->log('workspace.customer.merged', $customer, $before, ['target' => $customer->only(['id', 'name', 'phone', 'email'])], $tenant->id);

        return response()->json(['customer' => $customer]);
    }

    public function subscribePush(Request $request, Tenant $tenant, EntitlementService $entitlements, AuditService $audit): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless((string) $entitlements->get($tenant, 'push_enabled', '1') === '1', 403, 'PUSH_NOT_INCLUDED');
        $data = $request->validate([
            'endpoint' => 'required|url|max:2000',
            'keys.p256dh' => 'required|string|max:1000',
            'keys.auth' => 'required|string|max:500',
        ]);

        TenantPushSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id, 'endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'customer_id' => null,
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'locale' => in_array($tenant->locale, ['de', 'en', 'ru', 'uk'], true) ? $tenant->locale : 'de',
            ],
        );
        $audit->log('workspace.push.enabled', $tenant, null, ['user_id' => $request->user()->id], $tenant->id);

        return response()->json(['subscribed' => true]);
    }

    public function unsubscribePush(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $data = $request->validate(['endpoint' => 'required|url|max:2000']);
        $deleted = TenantPushSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();
        if ($deleted) {
            $audit->log('workspace.push.disabled', $tenant, ['user_id' => $request->user()->id], null, $tenant->id);
        }

        return response()->json(['subscribed' => false]);
    }

    public function team(Request $request, Tenant $tenant, EntitlementService $entitlements): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $limit = max(1, (int) $entitlements->get($tenant, 'staff_users', 1));

        return response()->json([
            'members' => $tenant->users()->orderByRaw("CASE tenant_users.role WHEN 'owner' THEN 0 ELSE 1 END")->orderBy('users.name')->get(['users.id', 'users.name', 'users.email', 'users.is_active'])->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->is_active,
                'role' => $user->pivot->role,
            ]),
            'limit' => $limit,
            'can_manage' => $this->canManageTeam($request, $tenant),
        ]);
    }

    public function addTeamMember(Request $request, Tenant $tenant, EntitlementService $entitlements, AuditService $audit): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($this->canManageTeam($request, $tenant), 403, 'OWNER_REQUIRED');
        $limit = max(1, (int) $entitlements->get($tenant, 'staff_users', 1));
        abort_if($tenant->users()->count() >= $limit, 422, 'TEAM_LIMIT_REACHED');
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:255',
            'role' => 'required|in:staff,manager',
        ]);

        $user = User::query()->where('email', mb_strtolower($data['email']))->first();
        $created = false;
        if (! $user) {
            $created = true;
            $user = User::create([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => Str::random(48),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        }
        abort_if($tenant->users()->whereKey($user->id)->exists(), 422, 'TEAM_MEMBER_EXISTS');
        $tenant->users()->attach($user->id, ['role' => $data['role']]);
        $setupUrl = null;
        if ($created) {
            $token = Password::broker()->createToken($user);
            $setupUrl = url('/reset-password/'.$token).'?email='.urlencode($user->email);
        }
        $audit->log('workspace.team_member.added', $user, null, ['name' => $user->name, 'email' => $user->email, 'role' => $data['role']], $tenant->id);

        return response()->json(['member' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $data['role']], 'setup_url' => $setupUrl], 201);
    }

    public function removeTeamMember(Request $request, Tenant $tenant, User $user, AuditService $audit): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($this->canManageTeam($request, $tenant), 403, 'OWNER_REQUIRED');
        $member = $tenant->users()->whereKey($user->id)->firstOrFail();
        abort_if($member->pivot->role === 'owner', 422, 'OWNER_CANNOT_BE_REMOVED');
        $before = ['name' => $member->name, 'email' => $member->email, 'role' => $member->pivot->role];
        $tenant->users()->detach($user->id);
        $audit->log('workspace.team_member.removed', $user, $before, null, $tenant->id);

        return response()->json(['deleted' => true]);
    }

    public function updateTeamMember(Request $request, Tenant $tenant, User $user, AuditService $audit): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($this->canManageTeam($request, $tenant), 403, 'OWNER_REQUIRED');
        $member = $tenant->users()->whereKey($user->id)->firstOrFail();
        abort_if($member->pivot->role === 'owner', 422, 'OWNER_CANNOT_BE_CHANGED');
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => 'required|in:staff,manager',
            'active' => 'required|boolean',
        ]);
        $before = ['name' => $user->name, 'email' => $user->email, 'active' => $user->is_active, 'role' => $member->pivot->role];
        $user->update(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'is_active' => $data['active']]);
        $tenant->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
        $audit->log('workspace.team_member.updated', $user, $before, ['name' => $user->name, 'email' => $user->email, 'active' => $user->is_active, 'role' => $data['role']], $tenant->id);

        return response()->json(['member' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'active' => $user->is_active, 'role' => $data['role']]]);
    }

    public function teamMemberSetupLink(Request $request, Tenant $tenant, User $user, AuditService $audit): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($this->canManageTeam($request, $tenant), 403, 'OWNER_REQUIRED');
        $member = $tenant->users()->whereKey($user->id)->firstOrFail();
        abort_if($member->pivot->role === 'owner', 422, 'OWNER_CANNOT_BE_CHANGED');
        $token = Password::broker()->createToken($user);
        $audit->log('workspace.team_member.setup_link_created', $user, null, ['email' => $user->email], $tenant->id);

        return response()->json(['setup_url' => url('/reset-password/'.$token).'?email='.urlencode($user->email)]);
    }

    private function canManageTeam(Request $request, Tenant $tenant): bool
    {
        return (bool) ($request->user()?->is_super_admin || $tenant->users()->whereKey($request->user()?->id)->wherePivot('role', 'owner')->exists());
    }

    private function requestItem(TenantRequest $r, bool $full = false): array
    {
        $base = ['id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'summary' => $r->summary, 'internal_note' => $r->internal_note, 'locale' => $r->locale, 'created_at' => $r->created_at?->toIso8601String(), 'customer' => $r->customer, 'media' => $r->media->map(fn ($m) => ['id' => $m->id, 'type' => $m->type, 'slot' => $m->slot_key, 'url' => Storage::disk('public')->url($m->storage_key)])];
        if ($full) {
            $base['values'] = $r->values;
            $base['messages'] = $r->messages;
            $base['details'] = $this->requestDetails($r);
            $base['ai_assessment'] = data_get($r->values->firstWhere('field_key', 'ai_condition_assessment')?->value, 'value');
        }

        return $base;
    }

    private function requestDetails(TenantRequest $tenantRequest): array
    {
        $locale = $tenantRequest->locale ?: 'de';
        $contact = (array) $tenantRequest->contact_snapshot;
        $labels = [
            'name' => ['de' => 'Name', 'en' => 'Name', 'ru' => 'Имя', 'uk' => 'Ім’я'],
            'phone' => ['de' => 'Telefon', 'en' => 'Phone', 'ru' => 'Телефон', 'uk' => 'Телефон'],
            'email' => ['de' => 'E-Mail', 'en' => 'Email', 'ru' => 'Электронная почта', 'uk' => 'Електронна пошта'],
            'preferred_channel' => ['de' => 'Bevorzugter Kontakt', 'en' => 'Preferred contact', 'ru' => 'Способ связи', 'uk' => 'Спосіб зв’язку'],
            'summary' => ['de' => 'Aufgabe', 'en' => 'Request', 'ru' => 'Что нужно сделать', 'uk' => 'Що потрібно зробити'],
        ];
        $details = collect(['name', 'phone', 'email', 'preferred_channel'])->map(fn (string $key) => [
            'key' => $key,
            'label' => $labels[$key][$locale] ?? $labels[$key]['de'],
            'value' => $contact[$key] ?? null,
        ])->all();
        $details[] = ['key' => 'summary', 'label' => $labels['summary'][$locale] ?? $labels['summary']['de'], 'value' => $tenantRequest->summary];

        $values = $tenantRequest->values->keyBy('field_key');
        $variationCode = (string) data_get($tenantRequest->contact_snapshot, 'business_variation_code', $tenantRequest->tenant?->businessProfile?->variation?->code);
        $configuration = $tenantRequest->template?->resolvedForVariation($variationCode, (array) config('tenant_apps.templates', [])) ?? [];
        foreach ((array) ($configuration['fields'] ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '' || $key === 'phone') {
                continue;
            }
            $stored = $values->get($key)?->value;
            $value = is_array($stored) && array_key_exists('value', $stored) ? $stored['value'] : $stored;
            $details[] = [
                'key' => $key,
                'label' => $this->localized($field['label'] ?? $key, $locale),
                'value' => $value,
            ];
        }

        return $details;
    }

    private function localized(mixed $value, string $locale): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return $value[$locale] ?? $value['de'] ?? $value['en'] ?? $value['ru'] ?? reset($value);
    }

    private function appointment($a): array
    {
        return [
            'id' => $a->id,
            'number' => $a->number,
            'status' => $a->status,
            'starts_at' => $a->starts_at?->toIso8601String(),
            'ends_at' => $a->ends_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'comment' => $a->comment,
            'service_mode' => data_get($a, 'contact_snapshot.service_mode', 'workshop'),
            'service_address' => data_get($a, 'contact_snapshot.service_address'),
            'customer' => $a->customer,
            'service' => $a->service,
        ];
    }

    private function freeSlots(Tenant $tenant, TenantCalendarService $calendar, string $date): array
    {
        $service = $tenant->services()->where('active', true)->first();

        return $service ? $calendar->slots($tenant, $service, $date) : [];
    }
}

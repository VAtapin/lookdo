<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantWorkspace;
use App\Models\Tenant;
use App\Models\TenantAppointment;
use App\Models\TenantCalendarBlock;
use App\Models\TenantReminder;
use App\Models\TenantService;
use App\Services\EntitlementService;
use App\Services\ImageStorageService;
use App\Services\LocalizedContentTranslationService;
use App\Services\TenantCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TenantCalendarController extends Controller
{
    use AuthorizesTenantWorkspace;

    public function index(Request $request, Tenant $tenant, TenantCalendarService $calendar, EntitlementService $entitlements): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $calendar->ensureWorkingHours($tenant);
        if (! $tenant->resources()->exists()) {
            $tenant->users()->orderBy('tenant_users.created_at')->get()->each(function ($user, $index) use ($tenant): void {
                $tenant->resources()->create([
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'kind' => 'staff',
                    'color' => $index === 0 ? '#ff6b00' : '#2f80ed',
                    'active' => true,
                    'sort_order' => $index,
                ]);
            });
        }
        $from = CarbonImmutable::parse($request->input('from', now()->startOfMonth()), TenantCalendarService::TIMEZONE)->startOfDay();
        $to = CarbonImmutable::parse($request->input('to', $from->addMonth()), TenantCalendarService::TIMEZONE)->endOfDay();

        return response()->json([
            'appointments' => $tenant->appointments()->with(['customer', 'service', 'resource'])->where('starts_at', '<', $to)->where('ends_at', '>', $from)->orderBy('starts_at')->get(),
            'blocks' => $tenant->calendarBlocks()->with('resource')->where('starts_at', '<', $to)->where('ends_at', '>', $from)->orderBy('starts_at')->get(),
            'working_hours' => $tenant->workingHours()->orderBy('weekday')->get(),
            'services' => $tenant->services()->whereNull('archived_at')->orderBy('sort_order')->get(),
            'reminders' => $tenant->reminders()->with(['customer', 'appointment'])->whereBetween('scheduled_at', [$from, $to])->orderBy('scheduled_at')->get(),
            'customers' => $tenant->customers()->orderBy('name')->orderBy('phone')->get(['id', 'name', 'phone']),
            'resources' => $tenant->resources()->with('user:id,name,email')->where('active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'entitlements' => $entitlements->all($tenant),
        ]);
    }

    public function saveWorkingHours(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $data = $request->validate([
            'days' => 'required|array|size:7',
            'days.*.weekday' => 'required|integer|between:1,7|distinct',
            'days.*.enabled' => 'required|boolean',
            'days.*.starts_at' => 'nullable|date_format:H:i',
            'days.*.ends_at' => 'nullable|date_format:H:i',
            'days.*.breaks' => 'nullable|array',
            'days.*.breaks.*.start' => 'required_with:days.*.breaks|date_format:H:i',
            'days.*.breaks.*.end' => 'required_with:days.*.breaks|date_format:H:i',
        ]);

        foreach ($data['days'] as $index => $day) {
            if ($day['enabled'] && (blank($day['starts_at'] ?? null) || blank($day['ends_at'] ?? null) || $day['starts_at'] >= $day['ends_at'])) {
                throw ValidationException::withMessages(["days.$index.ends_at" => 'INVALID_WORKING_HOURS']);
            }
        }

        DB::transaction(fn () => collect($data['days'])->each(
            fn ($day) => $tenant->workingHours()->updateOrCreate(
                ['weekday' => $day['weekday']],
                Arr::only($day, ['enabled', 'starts_at', 'ends_at', 'breaks']),
            ),
        ));

        return response()->json(['working_hours' => $tenant->workingHours()->orderBy('weekday')->get()]);
    }

    public function saveService(Request $request, Tenant $tenant, EntitlementService $entitlements, LocalizedContentTranslationService $translations, ?TenantService $service = null): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($this->enabled($entitlements, $tenant, 'services_enabled', true), 403, 'SERVICES_NOT_INCLUDED');
        if ($service) {
            abort_unless($service->tenant_id === $tenant->id, 404);
        }

        $data = $request->validate([
            'source_locale' => ['nullable', Rule::in(['de', 'en', 'ru', 'uk'])],
            'name' => 'required|array',
            'name.*' => 'nullable|string|max:160',
            'description' => 'nullable|array',
            'description.*' => 'nullable|string|max:10000',
            'duration_minutes' => 'required|integer|between:10,1440',
            'buffer_before_minutes' => 'nullable|integer|between:0,240',
            'buffer_after_minutes' => 'nullable|integer|between:0,240',
            'repeat_interval_days' => 'nullable|integer|between:1,3650',
            'price' => 'nullable|numeric|min:0',
            'currency' => 'required|string|size:3',
            'booking_enabled' => 'required|boolean',
            'media_allowed' => 'required|boolean',
            'active' => 'required|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $sourceLocale = $data['source_locale'] ?? (in_array($tenant->locale, ['de', 'en', 'ru', 'uk'], true) ? $tenant->locale : 'de');
        unset($data['source_locale']);
        if (blank(data_get($data, 'name.'.$sourceLocale))) {
            throw ValidationException::withMessages(['name' => 'Enter the service name in the primary language.']);
        }
        $enabledLocales = (array) data_get($tenant->profile?->content, 'enabled_locales', [$sourceLocale]);
        $translationLocales = array_values(array_unique([...$enabledLocales, $sourceLocale]));
        $sourceChanged = ! $service
            || data_get($service->name, $sourceLocale) !== data_get($data, 'name.'.$sourceLocale)
            || data_get($service->description, $sourceLocale, '') !== data_get($data, 'description.'.$sourceLocale, '');
        $translationMissing = collect($enabledLocales)
            ->reject(fn ($locale) => $locale === $sourceLocale)
            ->contains(fn ($locale) => blank(data_get($data, 'name.'.$locale)));

        if (count($translationLocales) > 1 && ($sourceChanged || $translationMissing)) {
            try {
                $localized = $translations->translateService(
                    (array) $data['name'],
                    (array) ($data['description'] ?? []),
                    $sourceLocale,
                    $translationLocales,
                    $request->user()?->id,
                    $tenant->id,
                );
                $data['name'] = $localized['name'];
                $data['description'] = $localized['description'];
            } catch (Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages(['translation' => 'Automatic translation failed: '.$exception->getMessage()]);
            }
        }

        $service ? $service->update($data) : $service = $tenant->services()->create($data);

        return response()->json(['service' => $service], $service->wasRecentlyCreated ? 201 : 200);
    }

    public function translateService(Request $request, Tenant $tenant, TenantService $service, LocalizedContentTranslationService $translations, EntitlementService $entitlements): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($this->enabled($entitlements, $tenant, 'services_enabled', true), 403, 'SERVICES_NOT_INCLUDED');
        abort_unless($service->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'source_locale' => ['required', Rule::in(['de', 'en', 'ru', 'uk'])],
            'name' => 'required|array',
            'name.*' => 'nullable|string|max:160',
            'description' => 'nullable|array',
            'description.*' => 'nullable|string|max:10000',
        ]);
        $enabledLocales = (array) data_get($tenant->profile?->content, 'enabled_locales', [$data['source_locale']]);

        try {
            $localized = $translations->translateService(
                (array) $data['name'],
                (array) ($data['description'] ?? []),
                $data['source_locale'],
                $enabledLocales,
                $request->user()?->id,
                $tenant->id,
            );
            $service->update($localized);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['translation' => 'Automatic translation failed: '.$exception->getMessage()]);
        }

        return response()->json(['service' => $service->fresh()]);
    }

    public function uploadServiceImage(Request $request, Tenant $tenant, TenantService $service, ImageStorageService $images): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($service->tenant_id === $tenant->id, 404);
        $data = $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:20480']);
        $oldPath = $service->image_path;
        $path = $images->storeUploaded($data['image'], 'tenant-app/'.$tenant->id.'/services', 'public', 1600, 1200);
        $service->update(['image_path' => $path]);
        if ($oldPath && $oldPath !== $path && str_starts_with($oldPath, 'tenant-app/'.$tenant->id.'/services/')) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json(['service' => $service->fresh(), 'image_url' => Storage::disk('public')->url($path)], 201);
    }

    public function removeServiceImage(Request $request, Tenant $tenant, TenantService $service): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($service->tenant_id === $tenant->id, 404);
        $oldPath = $service->image_path;
        $service->update(['image_path' => null]);
        if ($oldPath && str_starts_with($oldPath, 'tenant-app/'.$tenant->id.'/services/')) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json(['service' => $service->fresh(), 'image_url' => null]);
    }

    public function deleteService(Request $request, Tenant $tenant, TenantService $service): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($service->tenant_id === $tenant->id, 404);
        if ($service->appointments()->exists()) {
            $service->update(['active' => false, 'booking_enabled' => false, 'archived_at' => now()]);

            return response()->json(['deleted' => true, 'archived' => true]);
        }
        $imagePath = $service->image_path;
        $service->delete();
        if ($imagePath && str_starts_with($imagePath, 'tenant-app/'.$tenant->id.'/services/')) {
            Storage::disk('public')->delete($imagePath);
        }

        return response()->json(['deleted' => true]);
    }

    public function slots(Request $request, Tenant $tenant, TenantCalendarService $calendar): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $data = $request->validate([
            'service_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'resource_id' => ['nullable', 'integer', Rule::exists('tenant_resources', 'id')->where('tenant_id', $tenant->id)],
        ]);
        $service = $tenant->services()->findOrFail($data['service_id']);

        return response()->json(['slots' => $calendar->slots($tenant, $service, $data['date'], null, $data['resource_id'] ?? null)]);
    }

    public function saveAppointment(Request $request, Tenant $tenant, TenantCalendarService $calendar, EntitlementService $entitlements, ?TenantAppointment $appointment = null): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        if ($appointment) {
            abort_unless($appointment->tenant_id === $tenant->id, 404);
        }

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('tenant_customers', 'id')->where('tenant_id', $tenant->id)],
            'service_id' => ['required', 'integer', Rule::exists('tenant_services', 'id')->where('tenant_id', $tenant->id)],
            'resource_id' => ['nullable', 'integer', Rule::exists('tenant_resources', 'id')->where('tenant_id', $tenant->id)],
            'starts_at' => 'required|date',
            'status' => ['required', Rule::in(['pending', 'confirmed', 'completed', 'cancelled', 'no_show'])],
            'comment' => 'nullable|string|max:2000',
            'reminder_at' => 'nullable|date',
            'service_mode' => ['nullable', Rule::in(['workshop', 'on_site'])],
            'service_address' => 'nullable|required_if:service_mode,on_site|string|max:500',
        ]);

        $service = $tenant->services()->findOrFail($data['service_id']);
        $start = CarbonImmutable::parse($data['starts_at'], TenantCalendarService::TIMEZONE);
        $end = $start->addMinutes($service->duration_minutes);

        DB::transaction(function () use ($tenant, $service, $start, $end, $data, $calendar, $entitlements, &$appointment): void {
            $calendar->assertAvailable($tenant, $service, $start, $end, $appointment?->id, $data['resource_id'] ?? null);
            $serviceMode = $data['service_mode'] ?? data_get($appointment, 'contact_snapshot.service_mode', 'workshop');
            $serviceAddress = $serviceMode === 'on_site'
                ? ($data['service_address'] ?? data_get($appointment, 'contact_snapshot.service_address'))
                : null;
            $appointmentData = Arr::except($data, ['service_mode', 'service_address']);
            $payload = array_merge($appointmentData, [
                'ends_at' => $end,
                'number' => $appointment?->number ?: 'A-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'locale' => $tenant->locale,
                'contact_snapshot' => array_replace((array) $appointment?->contact_snapshot, [
                    'service_mode' => $serviceMode,
                    'service_address' => $serviceAddress,
                ]),
            ]);
            $appointment ? $appointment->update($payload) : $appointment = $tenant->appointments()->create($payload);

            if (filled($data['reminder_at'] ?? null) && filled($data['customer_id'] ?? null) && $this->enabled($entitlements, $tenant, 'reminders_enabled', false)) {
                $tenant->reminders()->updateOrCreate(
                    ['appointment_id' => $appointment->id, 'type' => 'appointment'],
                    [
                        'customer_id' => $data['customer_id'],
                        'channel' => 'push',
                        'status' => 'scheduled',
                        'scheduled_at' => $data['reminder_at'],
                        'message' => $this->appointmentReminder($tenant->locale, $start),
                        'sent_at' => null,
                        'error' => null,
                    ],
                );
            } else {
                $tenant->reminders()->where('appointment_id', $appointment->id)->where('type', 'appointment')->whereIn('status', ['scheduled', 'failed', 'skipped'])->delete();
            }
        });

        return response()->json(['appointment' => $appointment->fresh(['customer', 'service', 'resource'])], $appointment->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteAppointment(Request $request, Tenant $tenant, TenantAppointment $appointment): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($appointment->tenant_id === $tenant->id, 404);
        $appointment->reminders()->delete();
        $appointment->delete();

        return response()->json(['deleted' => true]);
    }

    public function saveBlock(Request $request, Tenant $tenant, ?TenantCalendarBlock $block = null): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        if ($block) {
            abort_unless($block->tenant_id === $tenant->id, 404);
        }
        $data = $request->validate([
            'kind' => 'required|in:blocked,vacation,exception,personal',
            'reason' => 'nullable|string|max:190',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'all_day' => 'required|boolean',
            'resource_id' => ['nullable', 'integer', Rule::exists('tenant_resources', 'id')->where('tenant_id', $tenant->id)],
        ]);
        $block ? $block->update($data) : $block = $tenant->calendarBlocks()->create($data);

        return response()->json(['block' => $block], $block->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteBlock(Request $request, Tenant $tenant, TenantCalendarBlock $block): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($block->tenant_id === $tenant->id, 404);
        $block->delete();

        return response()->json(['deleted' => true]);
    }

    public function saveReminder(Request $request, Tenant $tenant, EntitlementService $entitlements, ?TenantReminder $reminder = null): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($this->enabled($entitlements, $tenant, 'reminders_enabled', false), 403, 'REMINDERS_NOT_INCLUDED');
        if ($reminder) {
            abort_unless($reminder->tenant_id === $tenant->id, 404);
        }

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('tenant_customers', 'id')->where('tenant_id', $tenant->id)],
            'appointment_id' => ['nullable', 'integer', Rule::exists('tenant_appointments', 'id')->where('tenant_id', $tenant->id)],
            'type' => 'required|in:appointment,agreement,repeat_visit,vacancy',
            'channel' => 'required|in:push,sms,email,whatsapp',
            'scheduled_at' => 'required|date',
            'message' => 'required|string|max:1000',
        ]);

        if ($data['type'] === 'repeat_visit') {
            abort_unless($this->enabled($entitlements, $tenant, 'repeat_visit_enabled', false), 403, 'REPEAT_VISITS_NOT_INCLUDED');
        }
        if ($data['type'] === 'vacancy') {
            abort_unless($this->enabled($entitlements, $tenant, 'vacancy_fill_enabled', false), 403, 'VACANCY_FILL_NOT_INCLUDED');
        }
        if ($data['channel'] === 'sms') {
            abort_unless($this->enabled($entitlements, $tenant, 'sms_enabled', false), 403, 'SMS_NOT_INCLUDED');
        }

        $data['status'] = 'scheduled';
        $data['sent_at'] = null;
        $data['error'] = null;
        $reminder ? $reminder->update($data) : $reminder = $tenant->reminders()->create($data);

        return response()->json(['reminder' => $reminder], $reminder->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteReminder(Request $request, Tenant $tenant, TenantReminder $reminder): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($reminder->tenant_id === $tenant->id, 404);
        abort_if(in_array($reminder->status, ['sent', 'queued'], true), 422, 'REMINDER_ALREADY_SENT');
        $reminder->delete();

        return response()->json(['deleted' => true]);
    }

    private function enabled(EntitlementService $entitlements, Tenant $tenant, string $key, bool $default): bool
    {
        return filter_var($entitlements->get($tenant, $key, $default), FILTER_VALIDATE_BOOL);
    }

    private function appointmentReminder(string $locale, CarbonImmutable $start): string
    {
        $when = $start->setTimezone(TenantCalendarService::TIMEZONE)->format('d.m.Y H:i');

        return match ($locale) {
            'ru' => "Напоминаем о вашей записи $when.",
            'uk' => "Нагадуємо про ваш запис $when.",
            'en' => "Reminder: your appointment is scheduled for $when.",
            default => "Erinnerung: Ihr Termin ist am $when.",
        };
    }
}

<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class TenantCalendarService
{
    public const TIMEZONE = 'Europe/Berlin';

    public function ensureWorkingHours(Tenant $tenant): void
    {
        if ($tenant->workingHours()->exists()) {
            return;
        }
        foreach (range(1, 7) as $weekday) {
            $tenant->workingHours()->create([
                'weekday' => $weekday, 'enabled' => $weekday <= 5,
                'starts_at' => $weekday <= 5 ? '09:00:00' : null,
                'ends_at' => $weekday <= 5 ? '18:00:00' : null,
                'breaks' => $weekday <= 5 ? [['start' => '13:00', 'end' => '14:00']] : [],
            ]);
        }
    }

    public function slots(Tenant $tenant, TenantService $service, string $date, ?int $exceptAppointmentId = null, ?int $resourceId = null): array
    {
        $this->ensureWorkingHours($tenant);
        $day = CarbonImmutable::parse($date, self::TIMEZONE);
        $hours = $tenant->workingHours()->where('weekday', $day->dayOfWeekIso)->first();
        if (! $hours?->enabled || ! $hours->starts_at || ! $hours->ends_at) {
            return [];
        }
        $open = CarbonImmutable::parse($date.' '.substr((string) $hours->starts_at, 0, 5), self::TIMEZONE);
        $close = CarbonImmutable::parse($date.' '.substr((string) $hours->ends_at, 0, 5), self::TIMEZONE);
        $appointments = $tenant->appointments()->whereNotIn('status', ['cancelled', 'no_show'])
            ->when($exceptAppointmentId, fn ($query) => $query->whereKeyNot($exceptAppointmentId))
            ->when($resourceId, fn ($query) => $query->where(fn ($resources) => $resources->whereNull('resource_id')->orWhere('resource_id', $resourceId)))
            ->whereDate('starts_at', $date)->get();
        $blocks = $tenant->calendarBlocks()
            ->when($resourceId, fn ($query) => $query->where(fn ($resources) => $resources->whereNull('resource_id')->orWhere('resource_id', $resourceId)))
            ->where('starts_at', '<', $close)->where('ends_at', '>', $open)->get();
        $breaks = collect($hours->breaks ?: [])->map(fn ($b) => [
            CarbonImmutable::parse($date.' '.$b['start'], self::TIMEZONE), CarbonImmutable::parse($date.' '.$b['end'], self::TIMEZONE),
        ]);
        $slots = [];
        $cursor = $open;
        $step = 30;
        while ($cursor->addMinutes($service->duration_minutes + $service->buffer_after_minutes)->lte($close)) {
            $start = $cursor;
            $end = $cursor->addMinutes($service->duration_minutes);
            $busyStart = $start->subMinutes($service->buffer_before_minutes);
            $busyEnd = $end->addMinutes($service->buffer_after_minutes);
            $busy = $busyStart->lt($open) || $busyEnd->gt($close)
                || $appointments->contains(fn ($a) => $a->starts_at->lt($busyEnd) && $a->ends_at->gt($busyStart))
                || $blocks->contains(fn ($b) => $b->starts_at->lt($busyEnd) && $b->ends_at->gt($busyStart))
                || $breaks->contains(fn ($b) => $b[0]->lt($busyEnd) && $b[1]->gt($busyStart));
            if (! $busy && $start->isFuture()) {
                $slots[] = ['starts_at' => $start->toIso8601String(), 'label' => $start->format('H:i')];
            }
            $cursor = $cursor->addMinutes($step);
        }

        return $slots;
    }

    public function assertAvailable(Tenant $tenant, TenantService $service, CarbonImmutable $start, CarbonImmutable $end, ?int $exceptId = null, ?int $resourceId = null): void
    {
        $this->ensureWorkingHours($tenant);
        $localStart = $start->setTimezone(self::TIMEZONE);
        $localEnd = $end->setTimezone(self::TIMEZONE);
        $hours = $tenant->workingHours()->where('weekday', $localStart->dayOfWeekIso)->first();
        $open = $hours?->starts_at ? CarbonImmutable::parse($localStart->toDateString().' '.substr((string) $hours->starts_at, 0, 5), self::TIMEZONE) : null;
        $close = $hours?->ends_at ? CarbonImmutable::parse($localStart->toDateString().' '.substr((string) $hours->ends_at, 0, 5), self::TIMEZONE) : null;
        $from = $localStart->subMinutes($service->buffer_before_minutes);
        $to = $localEnd->addMinutes($service->buffer_after_minutes);
        $insideHours = $hours?->enabled && $open && $close && $from->gte($open) && $to->lte($close) && $localStart->isSameDay($localEnd);
        $insideBreak = collect($hours?->breaks ?: [])->contains(function (array $break) use ($localStart, $from, $to): bool {
            $breakStart = CarbonImmutable::parse($localStart->toDateString().' '.$break['start'], self::TIMEZONE);
            $breakEnd = CarbonImmutable::parse($localStart->toDateString().' '.$break['end'], self::TIMEZONE);

            return $breakStart->lt($to) && $breakEnd->gt($from);
        });
        if (! $insideHours || $insideBreak || ! $localStart->isFuture()) {
            throw ValidationException::withMessages(['starts_at' => 'CALENDAR_SLOT_UNAVAILABLE']);
        }

        $busy = $tenant->appointments()->whereNotIn('status', ['cancelled', 'no_show'])->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->when($resourceId, fn ($query) => $query->where(fn ($resources) => $resources->whereNull('resource_id')->orWhere('resource_id', $resourceId)))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->lockForUpdate()->exists();
        $blocked = $tenant->calendarBlocks()
            ->when($resourceId, fn ($query) => $query->where(fn ($resources) => $resources->whereNull('resource_id')->orWhere('resource_id', $resourceId)))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->exists();
        if ($busy || $blocked) {
            throw ValidationException::withMessages(['starts_at' => 'CALENDAR_SLOT_UNAVAILABLE']);
        }
    }
}

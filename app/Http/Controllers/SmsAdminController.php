<?php

namespace App\Http\Controllers;

use App\Models\SmsMessage;
use App\Models\SystemSetting;
use App\Models\TenantReminder;
use App\Services\SmsGateway;
use App\Services\TenantWebPushService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

class SmsAdminController extends Controller
{
    public function index(Request $request, SmsGateway $gateway, TenantWebPushService $webPush): JsonResponse
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(['queued', 'sending', 'accepted', 'delivered', 'failed'])],
            'sort' => ['nullable', Rule::in(['created_at', 'status', 'event_type', 'cost'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => 'nullable|integer|in:10,25,50,100',
        ]);
        $query = SmsMessage::with('tenant:id,name,slug');
        if ($search = trim((string) ($data['search'] ?? ''))) {
            $phone = preg_replace('/[\s().-]+/', '', $search) ?: '';
            $query->where(function ($builder) use ($search, $phone): void {
                $builder->where('event_type', 'like', "%{$search}%")
                    ->orWhere('provider_message_id', 'like', "%{$search}%")
                    ->orWhere('error_message', 'like', "%{$search}%")
                    ->orWhereHas('tenant', fn ($tenant) => $tenant->where('name', 'like', "%{$search}%"));
                if (preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
                    $builder->orWhere('recipient_hash', hash('sha256', $phone));
                }
            });
        }
        if ($status = $data['status'] ?? null) {
            $query->where('status', $status);
        }
        $paginator = $query->orderBy($data['sort'] ?? 'created_at', $data['direction'] ?? 'desc')
            ->paginate($data['per_page'] ?? 25);
        $month = SmsMessage::where('created_at', '>=', now()->startOfMonth());
        $dispatch = (array) SystemSetting::read('reminder_dispatch_status', []);
        $workerHeartbeat = (array) SystemSetting::read('queue_worker_heartbeat', []);
        try {
            $lastFinished = filled($dispatch['last_finished_at'] ?? null)
                ? CarbonImmutable::parse($dispatch['last_finished_at'])
                : null;
        } catch (Throwable) {
            $lastFinished = null;
        }
        try {
            $workerLastRun = filled($workerHeartbeat['last_run_at'] ?? null)
                ? CarbonImmutable::parse($workerHeartbeat['last_run_at'])
                : null;
        } catch (Throwable) {
            $workerLastRun = null;
        }

        return response()->json(array_merge($paginator->toArray(), [
            'summary' => [
                'sent' => (clone $month)->whereNotIn('status', ['queued', 'failed'])->count(),
                'delivered' => (clone $month)->where('status', 'delivered')->count(),
                'failed' => (clone $month)->where('status', 'failed')->count(),
                'cost' => (float) (clone $month)->sum('cost'),
                'currency' => 'EUR',
            ],
            'reminders' => [
                'last_run_at' => $lastFinished?->toIso8601String(),
                'scheduler_healthy' => $lastFinished?->greaterThanOrEqualTo(now()->subMinutes(3)) ?? false,
                'last_result' => $dispatch['last_result'] ?? null,
                'scheduled' => TenantReminder::where('status', 'scheduled')->count(),
                'due' => TenantReminder::where('status', 'scheduled')->where('scheduled_at', '<=', now())->count(),
                'failed' => TenantReminder::where('status', 'failed')->count(),
                'push_configured' => $webPush->configured(),
                'sms_configured' => $gateway->configured(),
                'queue_connection' => config('queue.default'),
                'queue_worker_last_run_at' => $workerLastRun?->toIso8601String(),
                'queue_worker_healthy' => $workerLastRun?->greaterThanOrEqualTo(now()->subMinutes(3)) ?? false,
                'queue_pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
                'sms_waiting' => SmsMessage::whereIn('status', ['queued', 'sending'])->count(),
            ],
        ]));
    }

    public function testConnection(SmsGateway $gateway): JsonResponse
    {
        if (! $gateway->enabled()) {
            return response()->json(['message' => 'SMS-Versand ist deaktiviert. Aktivieren und speichern Sie zuerst die globale SMS-Freigabe.'], 422);
        }
        if (! $gateway->configured()) {
            return response()->json(['message' => 'SMS ist noch nicht vollständig konfiguriert. Prüfen Sie Provider und API-Key.'], 422);
        }

        try {
            $balance = $gateway->balance();
        } catch (Throwable $exception) {
            return response()->json(['message' => 'seven.io konnte nicht erreicht oder authentifiziert werden: '.$exception->getMessage()], 502);
        }

        return response()->json([
            'configured' => true,
            'provider' => $gateway->provider(),
            'balance' => $balance,
        ]);
    }
}

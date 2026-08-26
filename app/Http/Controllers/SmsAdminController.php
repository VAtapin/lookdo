<?php

namespace App\Http\Controllers;

use App\Models\SmsMessage;
use App\Services\SmsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SmsAdminController extends Controller
{
    public function index(Request $request): JsonResponse
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

        return response()->json(array_merge($paginator->toArray(), [
            'summary' => [
                'sent' => (clone $month)->whereNotIn('status', ['queued', 'failed'])->count(),
                'delivered' => (clone $month)->where('status', 'delivered')->count(),
                'failed' => (clone $month)->where('status', 'failed')->count(),
                'cost' => (float) (clone $month)->sum('cost'),
                'currency' => 'EUR',
            ],
        ]));
    }

    public function testConnection(SmsGateway $gateway): JsonResponse
    {
        return response()->json([
            'configured' => $gateway->configured(),
            'provider' => $gateway->provider(),
            'balance' => $gateway->balance(),
        ]);
    }
}

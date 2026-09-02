<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminSubscriptionController extends Controller
{
    private const STATUSES = ['incomplete', 'trialing', 'active', 'past_due', 'canceled', 'complimentary'];

    public function show(Subscription $subscription): JsonResponse
    {
        return response()->json($this->details($subscription));
    }

    public function updateStatus(Request $request, Subscription $subscription, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'current_period_end' => ['nullable', 'date'],
            'cancel_at_period_end' => ['sometimes', 'boolean'],
        ]);

        $before = $subscription->only([
            'status',
            'current_period_end',
            'cancel_at_period_end',
            'complimentary',
            'manual_status_changed_at',
            'manual_status_reason',
            'manual_status_changed_by',
        ]);

        $subscription->update([
            'status' => $validated['status'],
            'current_period_end' => $validated['current_period_end'] ?? $subscription->current_period_end,
            'cancel_at_period_end' => $validated['cancel_at_period_end'] ?? $subscription->cancel_at_period_end,
            'complimentary' => $validated['status'] === 'complimentary',
            'manual_status_changed_at' => now(),
            'manual_status_reason' => $validated['reason'],
            'manual_status_changed_by' => $request->user()->id,
        ]);

        $audit->log(
            'subscription.status_changed_manually',
            $subscription,
            $before,
            $subscription->fresh()->only(array_keys($before)),
            $subscription->tenant_id,
        );

        return response()->json($this->details($subscription));
    }

    public function storePayment(Request $request, Subscription $subscription, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'other'])],
            'paid_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'grant_access' => ['sometimes', 'boolean'],
            'access_until' => ['nullable', 'required_if:grant_access,true', 'date', 'after:paid_at'],
        ]);

        [$payment, $subscriptionChanged, $subscriptionBefore] = DB::transaction(function () use ($request, $subscription, $validated): array {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $subscriptionBefore = $locked->only([
                'status',
                'current_period_start',
                'current_period_end',
                'complimentary',
                'manual_status_changed_at',
                'manual_status_reason',
                'manual_status_changed_by',
            ]);

            $payment = $locked->payments()->create([
                'amount' => $validated['amount'],
                'currency' => strtoupper($validated['currency']),
                'status' => 'paid',
                'paid_at' => $validated['paid_at'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'note' => $validated['note'] ?? null,
                'provider_payload' => ['source' => 'control', 'manual' => true],
                'recorded_by_user_id' => $request->user()->id,
            ]);
            $payment->update(['receipt_number' => sprintf('ZB-%s-%06d', $payment->paid_at->format('Y'), $payment->id)]);

            $subscriptionChanged = (bool) ($validated['grant_access'] ?? false);
            if ($subscriptionChanged) {
                $locked->update([
                    'status' => 'active',
                    'started_at' => $locked->started_at ?? $validated['paid_at'],
                    'current_period_start' => $validated['paid_at'],
                    'current_period_end' => $validated['access_until'],
                    'complimentary' => false,
                    'manual_status_changed_at' => now(),
                    'manual_status_reason' => 'Manuelle Zahlung '.$payment->receipt_number.' erfasst',
                    'manual_status_changed_by' => $request->user()->id,
                ]);
            }

            return [$payment->fresh(), $subscriptionChanged, $subscriptionBefore];
        });

        $audit->log(
            'subscription.payment_recorded_manually',
            $payment,
            null,
            $payment->only(['receipt_number', 'amount', 'currency', 'status', 'paid_at', 'payment_method', 'reference', 'note']),
            $subscription->tenant_id,
        );
        if ($subscriptionChanged) {
            $freshSubscription = $subscription->fresh();
            $audit->log(
                'subscription.activated_by_manual_payment',
                $freshSubscription,
                $subscriptionBefore,
                $freshSubscription->only(array_keys($subscriptionBefore)),
                $subscription->tenant_id,
            );
        }

        return response()->json($this->details($subscription), 201);
    }

    public function receipt(Subscription $subscription, SubscriptionPayment $payment): View
    {
        abort_unless($payment->subscription_id === $subscription->id, 404);

        $subscription->load(['tenant.users' => fn ($query) => $query->wherePivot('role', 'owner'), 'plan']);
        $payment->load('recordedBy');

        return view('admin.payment-receipt', [
            'subscription' => $subscription,
            'payment' => $payment,
            'documentNumber' => $payment->receipt_number
                ?: $payment->provider_payment_id
                ?: sprintf('ZB-%s-%06d', ($payment->paid_at ?? $payment->created_at)->format('Y'), $payment->id),
            'operator' => [
                'name' => SystemSetting::read('legal_operator_name', 'LOOKDO'),
                'address' => SystemSetting::read('legal_operator_address'),
                'email' => SystemSetting::read('legal_email'),
                'phone' => SystemSetting::read('legal_phone'),
                'vat_id' => SystemSetting::read('legal_vat_id'),
            ],
        ]);
    }

    private function details(Subscription $subscription): Subscription
    {
        return $subscription->fresh()->load([
            'tenant.users' => fn ($query) => $query->wherePivot('role', 'owner')->select('users.id', 'users.name', 'users.email'),
            'tenant.primaryDomain',
            'plan',
            'manualStatusChangedBy:id,name,email',
            'payments' => fn ($query) => $query->with('recordedBy:id,name,email')->latest('paid_at')->latest('id'),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
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
            'subscription_invoice_id' => ['nullable', 'integer', 'exists:subscription_invoices,id'],
        ]);

        $invoice = filled($validated['subscription_invoice_id'] ?? null)
            ? $subscription->invoices()->findOrFail($validated['subscription_invoice_id'])
            : null;

        [$payment, $subscriptionChanged, $subscriptionBefore] = DB::transaction(function () use ($request, $subscription, $validated, $invoice): array {
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
                'subscription_invoice_id' => $invoice?->id,
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
            if ($invoice && strtoupper((string) $invoice->currency) === strtoupper((string) $validated['currency'])) {
                $paidTotal = (float) $invoice->payments()->where('status', 'paid')->sum('amount');
                if ($paidTotal >= (float) $invoice->amount_total) {
                    $invoice->update(['status' => 'paid', 'paid_at' => $validated['paid_at']]);
                }
            }

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

    public function storeInvoice(Request $request, Subscription $subscription, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'amount_total' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $subscription->loadMissing(['tenant.profile', 'tenant.users', 'plan']);
        $total = round((float) $validated['amount_total'], 2);
        $taxRate = round((float) $validated['tax_rate'], 2);
        $net = round($total / (1 + $taxRate / 100), 2);
        $tax = round($total - $net, 2);
        $profile = $subscription->tenant->profile;
        $recipientAddress = collect([
            $profile?->street,
            trim(implode(' ', array_filter([$profile?->postal_code, $profile?->city]))),
            $subscription->tenant->country,
        ])->filter()->implode("\n");

        $invoice = DB::transaction(function () use ($request, $subscription, $validated, $total, $taxRate, $net, $tax, $recipientAddress): SubscriptionInvoice {
            $invoice = $subscription->invoices()->create([
                'tenant_id' => $subscription->tenant_id,
                'status' => 'open',
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'period_start' => $validated['period_start'] ?? null,
                'period_end' => $validated['period_end'] ?? null,
                'description' => $validated['description'],
                'amount_net' => $net,
                'tax_rate' => $taxRate,
                'tax_amount' => $tax,
                'amount_total' => $total,
                'currency' => strtoupper($validated['currency']),
                'recipient_name' => $subscription->tenant->name,
                'recipient_address' => $recipientAddress,
                'notes' => $validated['notes'] ?? null,
                'created_by_user_id' => $request->user()->id,
            ]);
            $invoice->update(['invoice_number' => sprintf('RE-%s-%06d', $invoice->issue_date->format('Y'), $invoice->id)]);

            return $invoice->fresh();
        });

        $audit->log('subscription.invoice_issued', $invoice, null, $invoice->toArray(), $subscription->tenant_id);

        return response()->json($this->details($subscription), 201);
    }

    public function updateInvoice(Request $request, Subscription $subscription, SubscriptionInvoice $invoice, AuditService $audit): JsonResponse
    {
        abort_unless($invoice->subscription_id === $subscription->id, 404);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'void'])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $before = $invoice->toArray();
        $invoice->update(['status' => $validated['status'], 'notes' => trim(implode("\n", array_filter([$invoice->notes, $validated['reason']])))]);
        $audit->log('subscription.invoice_status_changed', $invoice, $before, $invoice->fresh()->toArray(), $subscription->tenant_id);

        return response()->json($this->details($subscription));
    }

    public function invoice(Subscription $subscription, SubscriptionInvoice $invoice): View
    {
        abort_unless($invoice->subscription_id === $subscription->id, 404);

        return $this->invoiceView($invoice);
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
            'invoices' => fn ($query) => $query->with('payments:id,subscription_invoice_id,receipt_number,status')->latest('issue_date')->latest('id'),
        ]);
    }

    private function invoiceView(SubscriptionInvoice $invoice): View
    {
        $invoice->loadMissing(['tenant.users' => fn ($query) => $query->wherePivot('role', 'owner'), 'subscription.plan', 'payments']);

        return view('admin.invoice', [
            'invoice' => $invoice,
            'operator' => [
                'name' => SystemSetting::read('legal_operator_name', 'LOOKDO'),
                'address' => SystemSetting::read('legal_operator_address'),
                'email' => SystemSetting::read('legal_email'),
                'phone' => SystemSetting::read('legal_phone'),
                'vat_id' => SystemSetting::read('legal_vat_id'),
                'register' => SystemSetting::read('legal_register'),
            ],
        ]);
    }
}

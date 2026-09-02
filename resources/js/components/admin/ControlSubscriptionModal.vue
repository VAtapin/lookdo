<script setup lang="ts">
import AdminModal from "./AdminModal.vue";

const props = defineProps<{ ctx: any }>();
const {
    selectedSubscription,
    closeSubscription,
    formatDate,
    subscriptionAccessClass,
    subscriptionAccessLabel,
    subscriptionPaymentForm,
    subscriptionStatusForm,
    saveSubscriptionPayment,
    saveSubscriptionStatus,
    openPaymentReceipt,
    busy,
    error,
} = props.ctx;

const paymentMethodLabel = (method: string) =>
    ({
        cash: "Barzahlung",
        bank_transfer: "Überweisung",
        card: "Kartenzahlung",
        other: "Sonstige Zahlung",
    })[method] || "Online-Zahlung";
</script>

<template>
    <AdminModal
        v-if="selectedSubscription"
        title="Abonnement & Zahlungen"
        wide
        @close="closeSubscription"
    >
        <div class="subscription-admin">
            <p v-if="error" class="alert error">{{ error }}</p>
            <section class="subscription-summary">
                <div>
                    <span>Kunde</span><strong>{{ selectedSubscription.tenant?.name }}</strong
                    ><small>{{ selectedSubscription.tenant?.slug }}</small>
                </div>
                <div>
                    <span>Tarif</span
                    ><strong>{{ selectedSubscription.plan?.name?.de || selectedSubscription.plan?.code }}</strong
                    ><small>{{ selectedSubscription.provider === "manual" ? "Manuell" : selectedSubscription.provider }}</small>
                </div>
                <div>
                    <span>Status</span
                    ><strong class="table-status" :class="subscriptionAccessClass(selectedSubscription)">{{ subscriptionAccessLabel(selectedSubscription) }}</strong
                    ><small>bis {{ formatDate(selectedSubscription.current_period_end) }}</small>
                </div>
                <div>
                    <span>Zahlungen</span
                    ><strong>{{ selectedSubscription.payments?.length || 0 }}</strong
                    ><small>{{ selectedSubscription.provider_subscription_id || "Keine Provider-ID" }}</small>
                </div>
            </section>

            <p v-if="selectedSubscription.manual_status_changed_at" class="manual-change-note">
                Letzte manuelle Statusänderung: {{ formatDate(selectedSubscription.manual_status_changed_at) }}
                durch {{ selectedSubscription.manual_status_changed_by?.name || "unbekannt" }}.<br>
                Grund: {{ selectedSubscription.manual_status_reason }}
            </p>

            <details class="subscription-contract-details">
                <summary>Vertrags- und Providerdetails</summary>
                <dl>
                    <div><dt>Inhaber</dt><dd>{{ selectedSubscription.tenant?.users?.[0]?.name || "—" }}<small>{{ selectedSubscription.tenant?.users?.[0]?.email || "—" }}</small></dd></div>
                    <div><dt>Abrechnung</dt><dd>{{ selectedSubscription.billing_cycle === "yearly" ? "Jährlich" : "Monatlich" }}<small>{{ Number(selectedSubscription.unit_amount || 0).toFixed(2) }} {{ selectedSubscription.currency || selectedSubscription.plan?.currency }}</small></dd></div>
                    <div><dt>Beginn</dt><dd>{{ formatDate(selectedSubscription.started_at) }}</dd></div>
                    <div><dt>Aktuelle Periode</dt><dd>{{ formatDate(selectedSubscription.current_period_start) }}<small>bis {{ formatDate(selectedSubscription.current_period_end) }}</small></dd></div>
                    <div><dt>Provider-Kunde</dt><dd>{{ selectedSubscription.provider_customer_id || "—" }}</dd></div>
                    <div><dt>Provider-Abonnement</dt><dd>{{ selectedSubscription.provider_subscription_id || "—" }}</dd></div>
                    <div><dt>Rabatt</dt><dd>{{ selectedSubscription.discount_percent || 0 }} %</dd></div>
                    <div><dt>Kündigung</dt><dd>{{ selectedSubscription.cancel_at_period_end ? "Zum Periodenende" : "Keine vorgemerkt" }}</dd></div>
                </dl>
            </details>

            <section class="billing-panel wide-panel">
                <header><div><h3>Zahlungsverlauf</h3><p>Manuelle und über Stripe eingegangene Zahlungen.</p></div></header>
                <div class="payment-history">
                    <article v-for="payment in selectedSubscription.payments || []" :key="payment.id">
                        <div><b>{{ payment.receipt_number || payment.provider_payment_id || `Zahlung #${payment.id}` }}</b><small>{{ formatDate(payment.paid_at) }} · {{ paymentMethodLabel(payment.payment_method) }}</small></div>
                        <strong>{{ Number(payment.amount).toFixed(2) }} {{ payment.currency }}</strong>
                        <span class="table-status" :class="payment.status">{{ payment.status === "paid" ? "Bezahlt" : payment.status }}</span>
                        <button type="button" class="button ghost small" @click="openPaymentReceipt(payment)">Beleg</button>
                    </article>
                    <p v-if="!selectedSubscription.payments?.length" class="empty-payment-history">Noch keine Zahlungen erfasst.</p>
                </div>
            </section>

            <div class="billing-editor-grid">
                <section class="billing-panel">
                    <h3>Zahlung erfassen</h3>
                    <p>Für Barzahlung, Überweisung oder eine außerhalb von Stripe erhaltene Zahlung.</p>
                    <form class="modal-form" @submit.prevent="saveSubscriptionPayment">
                        <div class="billing-two-columns">
                            <label>Betrag<input v-model.number="subscriptionPaymentForm.amount" type="number" min="0.01" step="0.01" required></label>
                            <label>Währung<input v-model.trim="subscriptionPaymentForm.currency" maxlength="3" required></label>
                            <label>Zahlungsart<select v-model="subscriptionPaymentForm.payment_method"><option value="cash">Barzahlung</option><option value="bank_transfer">Überweisung</option><option value="card">Kartenzahlung</option><option value="other">Sonstige</option></select></label>
                            <label>Zeitpunkt<input v-model="subscriptionPaymentForm.paid_at" type="datetime-local" required></label>
                        </div>
                        <label>Referenz (optional)<input v-model="subscriptionPaymentForm.reference" maxlength="255" placeholder="z. B. Quittung oder Überweisungszweck"></label>
                        <label>Interner Vermerk<textarea v-model="subscriptionPaymentForm.note" rows="3" maxlength="2000"></textarea></label>
                        <label class="check"><input v-model="subscriptionPaymentForm.grant_access" type="checkbox"><span>Zugang mit dieser Zahlung aktivieren</span></label>
                        <label v-if="subscriptionPaymentForm.grant_access">Zugang bezahlt bis<input v-model="subscriptionPaymentForm.access_until" type="datetime-local" required></label>
                        <button class="button" :disabled="busy">Zahlung speichern & Beleg erstellen</button>
                    </form>
                </section>

                <section class="billing-panel">
                    <h3>Status manuell ändern</h3>
                    <p>Die Änderung wird mit Administrator, Zeitpunkt und Begründung protokolliert.</p>
                    <form class="modal-form" @submit.prevent="saveSubscriptionStatus">
                        <label>Status<select v-model="subscriptionStatusForm.status"><option value="incomplete">Nicht bezahlt</option><option value="trialing">Testphase</option><option value="active">Aktiv / bezahlt</option><option value="past_due">Zahlung überfällig</option><option value="canceled">Gekündigt</option><option value="complimentary">Kostenlos freigeschaltet</option></select></label>
                        <label>Periodenende (optional)<input v-model="subscriptionStatusForm.current_period_end" type="datetime-local"></label>
                        <label class="check"><input v-model="subscriptionStatusForm.cancel_at_period_end" type="checkbox"><span>Zum Periodenende kündigen</span></label>
                        <label>Begründung<textarea v-model="subscriptionStatusForm.reason" rows="4" minlength="5" maxlength="2000" required placeholder="Warum wird der Status manuell geändert?"></textarea></label>
                        <button class="button ghost" :disabled="busy">Status speichern</button>
                    </form>
                </section>
            </div>
            <p class="billing-warning">Hinweis: Ein späteres Stripe-Ereignis kann den Status eines Stripe-Abonnements erneut aktualisieren. Manuelle Zahlungen bleiben im Zahlungsverlauf erhalten.</p>
        </div>
    </AdminModal>
</template>

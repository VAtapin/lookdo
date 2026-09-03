<script setup lang="ts">
import AdminModal from "./AdminModal.vue";

const props = defineProps<{ ctx: any }>();
const {
    selectedTenant,
    saveTenantDetails,
    busy,
    formatDate,
    impersonate,
    toggleUser,
    resetPassword,
    tenantAccessLabel,
    lookups,
    updateTenant,
    manualAccessDays,
    grantTenantAccess,
    domainAction,
    deleteDomain,
    customTenantDomain,
    addTenantDomain,
    overrideForm,
    saveOverride,
    smsOverrideForm,
    saveSmsOverrides,
    resetSmsOverrides,
    entitlementGroups,
    selectedOverrideDefinition,
    selectOverride,
    tenantHasOverride,
    resetSelectedOverride,
    deleteTenantPermanently,
} = props.ctx;

function setOverrideBoolean(event: Event) {
    overrideForm.value = (event.target as HTMLInputElement).checked ? "1" : "0";
}

function visibleOverrideItems(items: any[]) {
    return items.filter(
        (item: any) =>
            !["sms_enabled", "sms_monthly_limit"].includes(item.key),
    );
}
</script>

<template>
    <AdminModal
        v-if="selectedTenant"
        :title="`Kunde #${selectedTenant.id} · ${selectedTenant.name}`"
        wide
        @close="selectedTenant = null"
    >
        <div class="tenant-dialog">
            <p class="tenant-dialog-domain">
                {{
                    selectedTenant.primary_domain?.domain ||
                    selectedTenant.slug + ".lookdo.app"
                }}
            </p>

        <section class="tenant-drawer-section">
            <h3>Kunde und Inhaber</h3>
            <form
                class="tenant-drawer-form"
                @submit.prevent="saveTenantDetails"
            >
                <label
                    >Unternehmen<input v-model="selectedTenant.name" required
                /></label>
                <label v-if="selectedTenant.users?.[0]"
                    >Name des Inhabers<input
                        v-model="selectedTenant.users[0].name"
                        required
                /></label>
                <label v-if="selectedTenant.users?.[0]"
                    >E-Mail des Inhabers<input
                        v-model="selectedTenant.users[0].email"
                        type="email"
                        required
                /></label>
                <button class="button small" :disabled="busy">
                    Stammdaten speichern
                </button>
            </form>
            <dl v-if="selectedTenant.users?.[0]">
                <div>
                    <dt>Zugang</dt>
                    <dd>
                        {{
                            selectedTenant.users[0].is_active
                                ? "aktiv"
                                : "gesperrt"
                        }}
                    </dd>
                </div>
                <div>
                    <dt>Letzte Anmeldung</dt>
                    <dd>
                        {{ formatDate(selectedTenant.users[0].last_login_at) }}
                    </dd>
                </div>
            </dl>
            <div class="drawer-actions">
                <button class="button" @click="impersonate">
                    Kundenkonto öffnen
                </button>
                <button
                    class="button ghost"
                    @click="toggleUser(selectedTenant.users[0])"
                >
                    {{
                        selectedTenant.users[0]?.is_active
                            ? "Inhaber sperren"
                            : "Inhaber aktivieren"
                    }}
                </button>
                <button
                    class="button ghost"
                    @click="resetPassword(selectedTenant.users[0])"
                >
                    Zugangslink senden
                </button>
            </div>
        </section>

        <section class="tenant-drawer-section">
            <h3>Tarif und Zugang</h3>
            <template v-if="selectedTenant.current_subscription">
                <div class="tenant-access-summary">
                    <span>Aktueller Zugang</span>
                    <strong>{{ tenantAccessLabel(selectedTenant) }}</strong>
                    <small
                        v-if="
                            selectedTenant.manual_access_until ||
                            selectedTenant.current_subscription
                                .access_expires_at
                        "
                        >Bis
                        {{
                            formatDate(
                                selectedTenant.manual_access_until ||
                                    selectedTenant.current_subscription
                                        .access_expires_at,
                            )
                        }}</small
                    >
                </div>
                <label
                    >Tarif<select
                        v-model.number="
                            selectedTenant.current_subscription.plan_id
                        "
                    >
                        <option
                            v-for="plan in lookups.plans"
                            :key="plan.id"
                            :value="plan.id"
                        >
                            {{ plan.name.de || plan.code }}
                        </option>
                    </select></label
                >
                <button
                    class="button ghost small"
                    @click="
                        updateTenant({
                            plan_id:
                                selectedTenant.current_subscription.plan_id,
                            discount_percent:
                                selectedTenant.current_subscription
                                    .discount_percent,
                        })
                    "
                >
                    Tarif speichern
                </button>
                <div class="manual-access-form">
                    <label
                        >Ohne Zahlung freischalten (Tage)<input
                            v-model.number="manualAccessDays"
                            type="number"
                            min="1"
                            max="3650"
                    /></label>
                    <button
                        class="button small"
                        :disabled="busy || manualAccessDays < 1"
                        @click="grantTenantAccess"
                    >
                        Für {{ manualAccessDays }} Tage freischalten
                    </button>
                </div>
            </template>
            <p v-else class="alert">
                Für diesen Kunden ist noch kein Tarif hinterlegt.
            </p>
            <button
                class="button ghost small"
                @click="
                    updateTenant({
                        status:
                            selectedTenant.status === 'active'
                                ? 'suspended'
                                : 'active',
                    })
                "
            >
                {{
                    selectedTenant.status === "active"
                        ? "Technischen Zugang sperren"
                        : "Technischen Zugang aktivieren"
                }}
            </button>
        </section>

        <section class="tenant-drawer-section">
            <h3>Domains</h3>
            <div class="tenant-domain-list">
                <article
                    v-for="domain in selectedTenant.domains"
                    :key="domain.id"
                >
                    <div>
                        <b>{{ domain.domain }}</b
                        ><small
                            >{{
                                domain.type === "custom"
                                    ? "Eigene Domain"
                                    : "LOOKDO-Adresse"
                            }}
                            · {{ domain.status }} · SSL
                            {{ domain.ssl_status || "—" }}</small
                        >
                    </div>
                    <div v-if="domain.type === 'custom'" class="table-actions">
                        <button @click="domainAction(domain, 'verify')">
                            Prüfen
                        </button>
                        <button
                            v-if="domain.status === 'ssl_pending'"
                            @click="domainAction(domain, 'activate')"
                        >
                            Aktivieren
                        </button>
                        <button
                            v-if="domain.status === 'active'"
                            @click="domainAction(domain, 'disable')"
                        >
                            Deaktivieren
                        </button>
                        <button
                            v-if="domain.status !== 'active'"
                            class="danger"
                            @click="deleteDomain(domain)"
                        >
                            Löschen
                        </button>
                    </div>
                </article>
            </div>
            <form class="tenant-domain-add" @submit.prevent="addTenantDomain">
                <input
                    v-model="customTenantDomain"
                    placeholder="firma.de"
                    required
                />
                <button class="button ghost small" :disabled="busy">
                    Domain hinzufügen
                </button>
            </form>
        </section>

        <section class="tenant-drawer-section">
            <h3>Individuelle Tarifleistungen</h3>
            <p class="tenant-entitlement-intro">
                Individuelle Freigaben gelten nur für diesen Kunden und haben
                Vorrang vor seinem Tarif.
            </p>

            <article class="tenant-sms-override">
                <header>
                    <div>
                        <b>SMS für diesen Kunden</b>
                        <small
                            >Versand freigeben und ein festes Monatslimit
                            setzen.</small
                        >
                    </div>
                    <span
                        :class="{
                            active:
                                tenantHasOverride('sms_enabled') ||
                                tenantHasOverride('sms_monthly_limit'),
                        }"
                    >{{
                        tenantHasOverride("sms_enabled") ||
                        tenantHasOverride("sms_monthly_limit")
                            ? "Individuell"
                            : "Tarifstandard"
                    }}</span>
                </header>
                <div class="tenant-sms-fields">
                    <label class="settings-toggle">
                        <input
                            v-model="smsOverrideForm.enabled"
                            type="checkbox"
                        />
                        <span>
                            <b>SMS-Versand aktivieren</b>
                            <small
                                >Erlaubt wichtige SMS an Endkunden dieses
                                Kundenkontos.</small
                            >
                        </span>
                    </label>
                    <label>
                        Monatliches SMS-Limit
                        <input
                            v-model.number="smsOverrideForm.monthly_limit"
                            type="number"
                            min="1"
                            max="10000"
                            inputmode="numeric"
                        />
                        <small
                            >Nach Erreichen des Limits werden keine weiteren
                            SMS versendet.</small
                        >
                    </label>
                </div>
                <div class="tenant-entitlement-actions">
                    <button
                        type="button"
                        class="button small"
                        :disabled="busy"
                        @click="saveSmsOverrides"
                    >
                        SMS-Einstellungen speichern
                    </button>
                    <button
                        v-if="
                            tenantHasOverride('sms_enabled') ||
                            tenantHasOverride('sms_monthly_limit')
                        "
                        type="button"
                        class="button ghost small"
                        :disabled="busy"
                        @click="resetSmsOverrides"
                    >
                        Tarifstandard verwenden
                    </button>
                </div>
            </article>

            <div class="tenant-other-override">
                <label>
                    Weitere Tarifleistung
                    <select
                        v-model="overrideForm.key"
                        @change="selectOverride"
                    >
                        <option value="">Leistung auswählen …</option>
                        <optgroup
                            v-for="group in entitlementGroups"
                            :key="group.key"
                            :label="group.label"
                        >
                            <option
                                v-for="item in visibleOverrideItems(
                                    group.items,
                                )"
                                :key="item.key"
                                :value="item.key"
                            >
                                {{ item.label }}
                            </option>
                        </optgroup>
                    </select>
                </label>
                <template v-if="selectedOverrideDefinition">
                    <label
                        v-if="selectedOverrideDefinition.type === 'boolean'"
                        class="settings-toggle tenant-generic-toggle"
                    >
                        <input
                            type="checkbox"
                            :checked="overrideForm.value === '1'"
                            @change="setOverrideBoolean"
                        />
                        <span>
                            <b>{{ selectedOverrideDefinition.label }}</b>
                            <small
                                >Für diesen Kunden individuell ein- oder
                                ausschalten.</small
                            >
                        </span>
                    </label>
                    <label v-else>
                        {{ selectedOverrideDefinition.label }}
                        <input
                            v-model="overrideForm.value"
                            type="number"
                            :min="selectedOverrideDefinition.min"
                            :max="selectedOverrideDefinition.max"
                            inputmode="numeric"
                        />
                        <small v-if="selectedOverrideDefinition.help">{{
                            selectedOverrideDefinition.help
                        }}</small>
                    </label>
                    <div class="tenant-entitlement-actions">
                        <button
                            type="button"
                            class="button ghost small"
                            :disabled="busy"
                            @click="saveOverride"
                        >
                            Individuell speichern
                        </button>
                        <button
                            v-if="tenantHasOverride(overrideForm.key)"
                            type="button"
                            class="button ghost small"
                            :disabled="busy"
                            @click="resetSelectedOverride"
                        >
                            Tarifstandard verwenden
                        </button>
                    </div>
                </template>
            </div>
        </section>

        <section class="tenant-drawer-section tenant-danger-zone">
            <h3>Kundenkonto löschen</h3>
            <p>
                Entfernt den Kunden, seine lokalen Daten und Dateien dauerhaft.
                Diese Aktion kann nicht rückgängig gemacht werden.
            </p>
            <button
                class="button ghost small danger"
                :disabled="busy"
                @click="deleteTenantPermanently"
            >
                Kunden endgültig löschen
            </button>
        </section>
        </div>
    </AdminModal>
</template>

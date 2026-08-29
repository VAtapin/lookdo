<script setup lang="ts">
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
    deleteTenantPermanently,
} = props.ctx;
</script>

<template>
    <aside v-if="selectedTenant" class="drawer tenant-drawer">
        <button class="drawer-close" @click="selectedTenant = null">×</button>
        <p class="eyebrow">KUNDE #{{ selectedTenant.id }}</p>
        <h2>{{ selectedTenant.name }}</h2>
        <p>
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
            <h3>Leistung überschreiben</h3>
            <input v-model="overrideForm.key" placeholder="Schlüssel" />
            <input v-model="overrideForm.value" placeholder="Wert" />
            <button class="button ghost small" @click="saveOverride">
                Speichern
            </button>
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
    </aside>
</template>

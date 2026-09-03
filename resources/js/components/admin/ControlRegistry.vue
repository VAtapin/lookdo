<script setup lang="ts">
import AdminPagination from "./AdminPagination.vue";
import ControlCustomerTables from "./ControlCustomerTables.vue";
import ControlOperationsTables from "./ControlOperationsTables.vue";
import ControlTemplateTables from "./ControlTemplateTables.vue";
import RegistryToolbar from "./RegistryToolbar.vue";

const props = defineProps<{ ctx: any }>();
const ctx = props.ctx;
const {
    section,
    data,
    filters,
    pager,
    addLabels,
    busy,
    openAdd,
    load,
    statusOptions,
    sortOptions,
    backupAction,
    backupTenantId,
    selectBackupTenant,
    tenantBackupAction,
    auditCleanupScope,
    askAuditCleanup,
    formatDate,
    changePage,
} = ctx;
</script>
<template>
    <section class="admin-section registry-page">
        <div v-if="section === 'sms'" class="sms-summary">
            <article>
                <span>Versendet im Monat</span
                ><strong>{{ data.summary?.sent || 0 }}</strong>
            </article>
            <article>
                <span>Zugestellt</span
                ><strong>{{ data.summary?.delivered || 0 }}</strong>
            </article>
            <article>
                <span>Fehlgeschlagen</span
                ><strong>{{ data.summary?.failed || 0 }}</strong>
            </article>
            <article>
                <span>Kosten im Monat</span
                ><strong
                    >{{ Number(data.summary?.cost || 0).toFixed(2) }}
                    {{ data.summary?.currency || "EUR" }}</strong
                >
            </article>
        </div>
        <RegistryToolbar
            v-model:search="filters.search"
            :total="pager.total"
            :add-label="addLabels[section]"
            :busy="busy"
            @add="openAdd"
            @refresh="load"
        >
            <select v-if="section === 'templates'" v-model="filters.secondary">
                <option value="">Alle Typen</option>
                <option value="category">Kategorien</option>
                <option value="variation">Varianten</option>
                <option value="template">Vorlagen</option>
            </select>
            <select
                v-else-if="section === 'subscriptions'"
                v-model="filters.secondary"
            >
                <option value="">Alle Anbieter</option>
                <option value="stripe">Stripe</option>
                <option value="lookdo">LOOKDO-Testphase</option>
                <option value="manual">Manuell</option></select
            ><select v-else-if="section === 'ai'" v-model="filters.secondary">
                <option value="">Alle Sprachen</option>
                <option value="de">Deutsch</option>
                <option value="en">Englisch</option>
                <option value="ru">Russisch</option>
                <option value="uk">Ukrainisch</option></select
            ><select
                v-else-if="section === 'classifications'"
                v-model="filters.secondary"
            >
                <option value="">Alle Quellen</option>
                <option value="dictionary">Wörterbuch</option>
                <option value="fuzzy">Ähnlichkeit</option>
                <option value="ai">KI</option>
                <option value="fallback">Standard</option>
            </select>
            <template v-else-if="section === 'audit'">
                <select v-model="filters.actor_id" aria-label="Nach Benutzer filtern">
                    <option value="">Alle Benutzer</option>
                    <option value="system">System</option>
                    <option v-for="actor in data.actors || []" :key="actor.id" :value="actor.id">
                        {{ actor.name }} · {{ actor.email }}
                    </option>
                </select>
                <select v-model="filters.tenant_id" aria-label="Nach Kunde filtern">
                    <option value="">Alle Kunden</option>
                    <option value="platform">Plattform / ohne Kunde</option>
                    <option v-for="tenant in data.tenants || []" :key="tenant.id" :value="tenant.id">
                        {{ tenant.name }} · {{ tenant.slug }}
                    </option>
                </select>
                <select v-model="filters.action" aria-label="Nach Aktion filtern">
                    <option value="">Alle Aktionen</option>
                    <option v-for="action in data.actions || []" :key="action" :value="action">
                        {{ action }}
                    </option>
                </select>
            </template>
            <select v-if="statusOptions[section]" v-model="filters.status">
                <option value="">Alle Status</option>
                <option
                    v-for="option in statusOptions[section]"
                    :key="option[0]"
                    :value="option[0]"
                >
                    {{ option[1] }}
                </option></select
            ><select v-model="filters.sort">
                <option
                    v-for="option in sortOptions[section] || []"
                    :key="option[0]"
                    :value="option[0]"
                >
                    {{ option[1] }}
                </option></select
            ><button
                type="button"
                class="sort-direction"
                @click="
                    filters.direction =
                        filters.direction === 'asc' ? 'desc' : 'asc'
                "
            >
                {{ filters.direction === "asc" ? "↑" : "↓" }}</button
            ><select v-model.number="filters.per_page">
                <option :value="10">10</option>
                <option :value="25">25</option>
                <option :value="50">50</option>
                <option :value="100">100</option>
            </select></RegistryToolbar
        >
        <div v-if="section === 'backups'" class="registry-context backup-tenant-context">
            <label>
                <span>Kunde</span>
                <select v-model="backupTenantId" @change="selectBackupTenant">
                    <option value="">Alle Kunden</option>
                    <option v-for="tenant in data.tenants || []" :key="tenant.id" :value="tenant.id">
                        {{ tenant.name }} · {{ tenant.slug }}
                    </option>
                </select>
            </label>
            <span>Getrennte Aufbewahrung: {{ data.keep }} Stände je Kunde</span>
            <button class="button" :disabled="busy || !backupTenantId" @click="tenantBackupAction('create')">
                {{ backupTenantId ? '＋ Vollständiges Kundenbackup' : 'Kunde für neues Backup auswählen' }}
            </button>
        </div>
        <div v-if="section === 'audit'" class="registry-context audit-cleanup-context">
            <span>Prüfprotokolle enthalten sicherheitsrelevante Änderungen. Gelöschte Einträge können nur aus einem Backup wiederhergestellt werden.</span>
            <select v-model="auditCleanupScope" aria-label="Zeitraum für Protokollbereinigung">
                <option value="30">Älter als 30 Tage</option>
                <option value="90">Älter als 90 Tage</option>
                <option value="180">Älter als 180 Tage</option>
                <option value="365">Älter als 1 Jahr</option>
                <option value="all">Gesamtes Prüfprotokoll</option>
            </select>
            <button type="button" class="button ghost small danger" :disabled="busy" @click="askAuditCleanup">Protokoll bereinigen</button>
        </div>
        <section v-if="section === 'sms'" class="reminder-delivery-status">
            <article :class="data.reminders?.scheduler_healthy ? 'ready' : 'warning'">
                <span>Planer</span><strong>{{ data.reminders?.scheduler_healthy ? 'Läuft' : 'Nicht erkannt' }}</strong><small>Letzter Lauf: {{ formatDate(data.reminders?.last_run_at) }}</small>
            </article>
            <article :class="data.reminders?.push_configured ? 'ready' : 'warning'">
                <span>Push</span><strong>{{ data.reminders?.push_configured ? 'Konfiguriert' : 'VAPID fehlt' }}</strong><small>{{ data.reminders?.scheduled || 0 }} geplant · {{ data.reminders?.due || 0 }} fällig</small>
            </article>
            <article :class="data.reminders?.sms_configured ? 'ready' : 'warning'">
                <span>SMS</span><strong>{{ data.reminders?.sms_configured ? 'seven.io bereit' : 'Nicht konfiguriert' }}</strong><small>{{ data.reminders?.sms_waiting || 0 }} SMS warten</small>
            </article>
            <article :class="data.reminders?.queue_worker_healthy ? 'ready' : 'warning'">
                <span>Queue-Worker</span><strong>{{ data.reminders?.queue_worker_healthy ? 'Läuft' : 'Nicht erkannt' }}</strong><small>Letzter Lauf: {{ formatDate(data.reminders?.queue_worker_last_run_at) }} · {{ data.reminders?.queue_pending ?? '—' }} Jobs warten</small>
            </article>
        </section>
        <div class="admin-table-wrap">
            <table>
                <ControlCustomerTables :ctx="ctx" />

                <ControlTemplateTables :ctx="ctx" />
                <ControlOperationsTables :ctx="ctx" />
            </table>
        </div>
        <div v-if="section === 'administrators'" class="registry-context">
            <span
                >Administratoren können hier angelegt, bearbeitet, gesperrt und
                gelöscht werden. Das eigene sowie das letzte verbleibende
                Administratorkonto sind geschützt.</span
            >
        </div>
        <div v-if="section === 'backups'" class="registry-context">
            <span>Systembackup (alle Kunden): {{ (data.platform_backups || []).length }} vorhanden</span
            ><span>Speicherort: {{ data.platform_path }}</span
            ><button
                class="button"
                :disabled="busy"
                @click="backupAction('create')"
            >
                ＋ Vollständiges Systembackup
            </button>
        </div>
        <AdminPagination
            :current="pager.current"
            :last="pager.last"
            :from="pager.from"
            :to="pager.to"
            :total="pager.total"
            @change="changePage"
        />
    </section>
</template>

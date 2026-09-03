<script setup lang="ts">
const props = defineProps<{ ctx: any }>();
const {
    section,
    rows,
    tenantBackupAction,
    formatDate,
    smsEventLabels,
    smsStatusLabels,
    selectedAudit,
    modal,
} = props.ctx;
</script>
<template>
    <thead v-if="section === 'backups'">
        <tr>
            <th>Backup</th>
            <th>Kunde</th>
            <th>Erstellt</th>
            <th>Inhalt</th>
            <th>Aktionen</th>
        </tr>
    </thead>
    <tbody v-if="section === 'backups'">
        <tr v-for="item in rows" :key="item.name">
            <td>
                <b>{{ item.name }}</b>
                <small>{{ item.reason === 'pre-restore' ? 'Sicherheitskopie vor Wiederherstellung' : item.reason === 'scheduled' ? 'Automatisch' : 'Manuell' }} · {{ item.scope === 'full_tenant' ? 'Vollständig' : 'Inhalte (älteres Format)' }}</small>
            </td>
            <td><b>{{ item.tenant_name }}</b><small>{{ item.tenant_slug }}</small></td>
            <td>{{ formatDate(item.created_at) }}</td>
            <td>
                {{ Object.values(item.rows || {}).reduce((sum: number, count: any) => sum + Number(count || 0), 0) + Number(item.user_count || 0) }} Datensätze ·
                {{ item.file_count || 0 }} Dateien
            </td>
            <td class="table-actions">
                <button @click="tenantBackupAction('verify', item.name, item.tenant_id)">
                    Prüfen</button
                ><button @click="tenantBackupAction('restore', item.name, item.tenant_id)">
                    Wiederherstellen</button
                ><button
                    class="danger"
                    @click="tenantBackupAction('delete', item.name, item.tenant_id)"
                >
                    Löschen
                </button>
            </td>
        </tr>
    </tbody>
    <thead v-if="section === 'sms'">
        <tr>
            <th>Datum</th>
            <th>Kunde</th>
            <th>Ereignis</th>
            <th>Empfänger</th>
            <th>Status</th>
            <th>Teile</th>
            <th>Kosten</th>
            <th>Provider-ID / Fehler</th>
        </tr>
    </thead>
    <tbody v-if="section === 'sms'">
        <tr v-for="item in rows" :key="item.id">
            <td>{{ formatDate(item.created_at) }}</td>
            <td>
                <b>{{ item.tenant?.name || "—" }}</b
                ><small>{{ item.tenant?.slug }}</small>
            </td>
            <td>
                {{ smsEventLabels[item.event_type] || item.event_type }}
            </td>
            <td>{{ item.recipient_masked }}</td>
            <td>
                <span class="table-status" :class="item.status">{{
                    smsStatusLabels[item.status] || item.status
                }}</span
                ><small>{{ item.provider_status }}</small>
            </td>
            <td>{{ item.parts }}</td>
            <td>
                {{ Number(item.cost || 0).toFixed(4) }}
                {{ item.currency }}
            </td>
            <td>
                <small>{{
                    item.provider_message_id || item.error_message || "—"
                }}</small>
            </td>
        </tr>
    </tbody>
    <thead v-if="section === 'audit'">
        <tr>
            <th>Datum</th>
            <th>Aktion</th>
            <th>Benutzer</th>
            <th>Kunde</th>
            <th>Objekt</th>
            <th>IP-Adresse</th>
        </tr>
    </thead>
    <tbody v-if="section === 'audit'">
        <tr
            v-for="item in rows"
            :key="item.id"
            class="audit-row"
            tabindex="0"
            @click="
                selectedAudit = item;
                modal = 'audit';
            "
            @keydown.enter="
                selectedAudit = item;
                modal = 'audit';
            "
        >
            <td>{{ formatDate(item.created_at) }}</td>
            <td>
                <b>{{ item.action }}</b>
            </td>
            <td>
                <b>{{ item.actor?.name || "System" }}</b>
                <small v-if="item.actor">{{ item.actor.email }}</small>
            </td>
            <td>
                <b>{{ item.tenant?.name || "Plattform" }}</b>
                <small v-if="item.tenant">{{ item.tenant.slug }}</small>
            </td>
            <td>
                {{
                    item.subject_type
                        ? `${item.subject_type.split("\\").pop()} #${item.subject_id}`
                        : "—"
                }}
            </td>
            <td>{{ item.ip_address || "—" }}</td>
        </tr>
    </tbody>
    <tbody v-if="!rows.length">
        <tr>
            <td colspan="9" class="empty-table">
                Keine passenden Einträge gefunden.
            </td>
        </tr>
    </tbody>
</template>

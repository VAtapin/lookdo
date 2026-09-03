<script setup lang="ts">
import AdminModal from "./AdminModal.vue";

defineProps<{ audit: Record<string, any> }>();
defineEmits<{ close: [] }>();

const formatDate = (value?: string | null) =>
    value ? new Intl.DateTimeFormat("de-DE", { dateStyle: "medium", timeStyle: "medium" }).format(new Date(value)) : "—";

const pretty = (value: unknown) => {
    if (value === null || value === undefined || value === "") return "—";
    if (typeof value === "string") {
        try {
            return JSON.stringify(JSON.parse(value), null, 2);
        } catch {
            return value;
        }
    }
    return JSON.stringify(value, null, 2);
};
</script>

<template>
    <AdminModal title="Protokolldetails" wide @close="$emit('close')">
        <div class="audit-details">
            <dl>
                <div><dt>Aktion</dt><dd>{{ audit.action }}</dd></div>
                <div><dt>Zeit</dt><dd>{{ formatDate(audit.created_at) }}</dd></div>
                <div>
                    <dt>Benutzer / Kunde</dt>
                    <dd>
                        {{ audit.actor?.name || "System" }} / {{ audit.tenant?.name || "Plattform" }}
                        <small v-if="audit.actor || audit.tenant">
                            {{ audit.actor?.email || "—" }} / {{ audit.tenant?.slug || "—" }}
                        </small>
                    </dd>
                </div>
                <div>
                    <dt>IP / Browser</dt>
                    <dd>{{ audit.ip_address || "—" }}<small>{{ audit.user_agent || "—" }}</small></dd>
                </div>
            </dl>
            <section><h3>Vorher</h3><pre>{{ pretty(audit.before) }}</pre></section>
            <section><h3>Nachher</h3><pre>{{ pretty(audit.after) }}</pre></section>
        </div>
    </AdminModal>
</template>

<script setup lang="ts">
import AdminModal from "./AdminModal.vue";
import AuditDetailsModal from "./AuditDetailsModal.vue";
const props = defineProps<{ ctx: any }>();
const { modal, confirmAction, busy, executeConfirmed, selectedAudit } =
    props.ctx;
</script>
<template>
    <AdminModal
        v-if="modal === 'confirm' && confirmAction"
        :title="confirmAction.title"
        @close="
            modal = '';
            confirmAction = null;
        "
        ><div class="confirm-dialog">
            <p>{{ confirmAction.message }}</p>
            <div class="modal-actions">
                <button
                    type="button"
                    class="button ghost"
                    @click="
                        modal = '';
                        confirmAction = null;
                    "
                >
                    Abbrechen</button
                ><button
                    type="button"
                    class="button"
                    :class="{ danger: confirmAction.danger }"
                    :disabled="busy"
                    @click="executeConfirmed"
                >
                    {{ confirmAction.confirmLabel }}
                </button>
            </div>
        </div></AdminModal
    >
    <AuditDetailsModal
        v-if="modal === 'audit' && selectedAudit"
        :audit="selectedAudit"
        @close="
            modal = '';
            selectedAudit = null;
        "
    />
</template>

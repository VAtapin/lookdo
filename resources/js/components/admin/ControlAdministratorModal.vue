<script setup lang="ts">
import AdminModal from "./AdminModal.vue";

const props = defineProps<{ ctx: any }>();
const {
    modal,
    selectedAdministrator,
    administratorForm,
    saveAdministrator,
    askDeleteAdministrator,
    busy,
    me,
} = props.ctx;
</script>

<template>
    <AdminModal
        v-if="modal === 'administrator'"
        :title="
            selectedAdministrator
                ? 'Administrator bearbeiten'
                : 'Administrator hinzufügen'
        "
        @close="modal = ''"
    >
        <form class="modal-form" @submit.prevent="saveAdministrator">
            <label
                >Name<input
                    v-model.trim="administratorForm.name"
                    maxlength="120"
                    autocomplete="name"
                    required
            /></label>
            <label
                >E-Mail<input
                    v-model.trim="administratorForm.email"
                    type="email"
                    maxlength="255"
                    autocomplete="email"
                    required
            /></label>
            <label
                >Sprache<select v-model="administratorForm.locale" required>
                    <option value="de">Deutsch</option>
                    <option value="en">English</option>
                    <option value="ru">Русский</option>
                    <option value="uk">Українська</option>
                </select></label
            >
            <label
                >{{
                    selectedAdministrator
                        ? "Neues Passwort (optional)"
                        : "Temporäres Passwort"
                }}<input
                    v-model="administratorForm.password"
                    type="password"
                    minlength="10"
                    autocomplete="new-password"
                    :required="!selectedAdministrator"
            /></label>
            <label v-if="administratorForm.password || !selectedAdministrator"
                >Passwort bestätigen<input
                    v-model="administratorForm.password_confirmation"
                    type="password"
                    minlength="10"
                    autocomplete="new-password"
                    :required="Boolean(administratorForm.password)"
            /></label>
            <label class="check"
                ><input
                    v-model="administratorForm.is_active"
                    type="checkbox"
                    :disabled="selectedAdministrator?.id === me?.user?.id"
                /><span>Administrator ist aktiv</span></label
            >
            <p class="registry-context">
                Dieses Konto erhält vollständigen Zugriff auf die
                LOOKDO-Verwaltung. Kundenkonten werden hier nicht zu
                Administratoren umgewandelt.
            </p>
            <div class="modal-actions administrator-modal-actions">
                <button
                    v-if="selectedAdministrator"
                    type="button"
                    class="button ghost danger"
                    :disabled="busy || selectedAdministrator.id === me?.user?.id"
                    @click="askDeleteAdministrator(selectedAdministrator)"
                >
                    Löschen
                </button>
                <span></span>
                <button type="button" class="button ghost" @click="modal = ''">
                    Abbrechen
                </button>
                <button class="button" :disabled="busy">
                    {{ selectedAdministrator ? "Speichern" : "Hinzufügen" }}
                </button>
            </div>
        </form>
    </AdminModal>
</template>

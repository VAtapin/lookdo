<script setup lang="ts">
import AdminModal from "./AdminModal.vue";
const props = defineProps<{ ctx: any }>();
const { modal, tenantForm, lookups, busy, createTenant } = props.ctx;
</script>
<template>
    <AdminModal
        v-if="modal === 'tenant'"
        title="Kunden anlegen"
        wide
        @close="modal = ''"
        ><form class="modal-form form-grid" @submit.prevent="createTenant">
            <label
                >Unternehmen<input v-model="tenantForm.name" required /></label
            ><label
                >Subdomain<input
                    v-model="tenantForm.slug"
                    placeholder="automatisch" /></label
            ><label
                >Name des Inhabers<input
                    v-model="tenantForm.owner_name"
                    required /></label
            ><label
                >E-Mail des Inhabers<input
                    v-model="tenantForm.owner_email"
                    type="email"
                    required /></label
            ><label
                >Temporäres Passwort<input
                    v-model="tenantForm.owner_password"
                    type="password"
                    minlength="10"
                    required /></label
            ><label
                >Tarif<select v-model.number="tenantForm.plan_id" required>
                    <option :value="null">Bitte wählen</option>
                    <option
                        v-for="plan in lookups.plans"
                        :key="plan.id"
                        :value="plan.id"
                    >
                        {{ plan.name.de || plan.code }}
                    </option>
                </select></label
            ><label
                >Geschäftsvorlage<select
                    v-model.number="tenantForm.variation_id"
                >
                    <option :value="null">Standardvorlage</option>
                    <option
                        v-for="variation in lookups.variations"
                        :key="variation.id"
                        :value="variation.id"
                    >
                        {{ variation.name.de || variation.code }}
                    </option>
                </select></label
            ><label class="check"
                ><input v-model="tenantForm.complimentary" type="checkbox" />
                Ohne Zahlung freischalten</label
            ><label v-if="tenantForm.complimentary"
                >Freischaltung (Tage)<input
                    v-model.number="tenantForm.complimentary_days"
                    type="number"
                    min="1"
                    max="3650"
                    required /></label
            ><label class="wide"
                >Tätigkeitsbeschreibung<textarea
                    v-model="tenantForm.business_description"
                ></textarea>
            </label>
            <div class="modal-actions wide">
                <button type="button" class="button ghost" @click="modal = ''">
                    Abbrechen</button
                ><button class="button" :disabled="busy">Kunden anlegen</button>
            </div>
        </form></AdminModal
    >
</template>

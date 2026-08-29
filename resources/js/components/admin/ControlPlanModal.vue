<script setup lang="ts">
import AdminModal from "./AdminModal.vue";
const props = defineProps<{ ctx: any }>();
const {
    modal,
    editingPlanId,
    busy,
    planImagePreview,
    planImageFile,
    selectPlanImage,
    clearPendingPlanImage,
    deletePlanImage,
    planForm,
    savePlan,
    translating,
    translatePlan,
    planLocales,
    planLocale,
    entitlementGroups,
} = props.ctx;
</script>
<template>
    <AdminModal
        v-if="modal === 'plan'"
        :title="editingPlanId ? 'Tarif bearbeiten' : 'Tarif anlegen'"
        wide
        @close="modal = ''"
    >
        <form
            class="modal-form form-grid plan-editor"
            @submit.prevent="savePlan"
        >
            <section class="plan-editor-section plan-image-editor wide">
                <div>
                    <h3>Tarifbild</h3>
                    <p>
                        Dieses Bild erscheint dezent im Kopf der Tarifkarte und
                        wird bei der Stripe-Synchronisierung an das Produkt
                        übertragen.
                    </p>
                    <small
                        >Empfohlen: Querformat, mindestens 1200 × 630 Pixel.
                        JPG, PNG oder WebP, maximal 8 MB.</small
                    >
                </div>
                <div class="plan-image-editor-layout">
                    <div
                        class="plan-image-admin-preview"
                        :class="{ empty: !planImagePreview }"
                    >
                        <img
                            decoding="async"
                            v-if="planImagePreview"
                            :src="planImagePreview"
                            alt="Tarifbild"
                        /><span v-else>Noch kein Tarifbild</span>
                    </div>
                    <div class="plan-image-editor-actions">
                        <label class="media-file-button"
                            ><input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                :disabled="busy"
                                @change="selectPlanImage"
                            /><span>Bild auswählen</span></label
                        ><button
                            v-if="planImageFile"
                            type="button"
                            class="button ghost small"
                            @click="clearPendingPlanImage"
                        >
                            Auswahl verwerfen</button
                        ><button
                            v-else-if="editingPlanId && planImagePreview"
                            type="button"
                            class="button ghost small danger"
                            :disabled="busy"
                            @click="deletePlanImage"
                        >
                            Bild entfernen
                        </button>
                    </div>
                </div>
            </section>
            <section class="plan-editor-section wide">
                <h3>Abrechnung & Veröffentlichung</h3>
                <p>
                    Monats- und Jahrespreise werden je Währung fest hinterlegt.
                    So kann ein Jahrespreis einen eigenen Rabatt enthalten, ohne
                    automatische Wechselkursberechnung.
                </p>
                <div class="plan-currency-grid">
                    <fieldset
                        v-for="currency in ['EUR', 'RUB', 'UAH']"
                        :key="currency"
                        class="plan-currency-card"
                    >
                        <legend>{{ currency }}</legend>
                        <label
                            >Monatspreis<input
                                v-model.number="
                                    planForm.prices[currency].monthly
                                "
                                type="number"
                                min="0"
                                step="0.01"
                                required /></label
                        ><label
                            >Jahrespreis<input
                                v-model.number="
                                    planForm.prices[currency].yearly
                                "
                                type="number"
                                min="0"
                                step="0.01"
                                required
                        /></label>
                    </fieldset>
                </div>
                <div class="form-grid plan-publishing">
                    <label>Code<input v-model="planForm.code" required /></label
                    ><label
                        >Testtage<input
                            v-model.number="planForm.trial_days"
                            type="number"
                            min="0" /></label
                    ><label
                        >Reihenfolge<input
                            v-model.number="planForm.sort_order"
                            type="number"
                            min="0" /></label
                    ><label class="check"
                        ><input v-model="planForm.is_active" type="checkbox" />
                        Aktiv</label
                    ><label class="check"
                        ><input v-model="planForm.is_public" type="checkbox" />
                        Öffentlich</label
                    >
                </div>
            </section>
            <section class="plan-editor-section wide">
                <div class="plan-section-head">
                    <div>
                        <h3>Texte in vier Sprachen</h3>
                        <p>
                            Wählen Sie die Sprache Ihres Ausgangstextes. Die KI
                            übersetzt Name, Beschreibung und Badge in die drei
                            anderen Sprachen.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="button small"
                        :disabled="translating"
                        @click="translatePlan"
                    >
                        {{
                            translating
                                ? "KI übersetzt…"
                                : "✦ Mit KI übersetzen"
                        }}
                    </button>
                </div>
                <div class="type-tabs plan-language-tabs">
                    <button
                        v-for="item in planLocales"
                        :key="item[0]"
                        type="button"
                        :class="{ active: planLocale === item[0] }"
                        @click="planLocale = item[0]"
                    >
                        {{ item[1]
                        }}<span v-if="planForm.name[item[0]]">✓</span>
                    </button>
                </div>
                <div class="form-grid">
                    <label
                        >Tarifname ({{
                            planLocales.find(
                                (item: [string, string]) =>
                                    item[0] === planLocale,
                            )?.[1]
                        }})<input
                            v-model="planForm.name[planLocale]"
                            required /></label
                    ><label
                        >Badge / Kennzeichnung<input
                            v-model="planForm.badge_text[planLocale]"
                            placeholder="z. B. Empfohlen" /></label
                    ><label class="wide"
                        >Beschreibung<textarea
                            v-model="planForm.description[planLocale]"
                            rows="3"
                        ></textarea>
                    </label>
                </div>
                <p class="plan-translation-note">
                    KI-Texte werden vor dem Speichern in die Felder eingefügt
                    und können manuell geprüft oder geändert werden. Preise,
                    Limits und Leistungen werden nicht von der KI verändert.
                </p>
            </section>
            <section class="plan-editor-section wide">
                <h3>Leistungen & Limits</h3>
                <p>
                    Diese Werte steuern die öffentliche Tarifdarstellung und
                    später die Freischaltung der Funktionen im Kundenkonto.
                </p>
                <div class="entitlement-groups">
                    <fieldset
                        v-for="group in entitlementGroups"
                        :key="group.key"
                        class="entitlement-group"
                    >
                        <legend>{{ group.label }}</legend>
                        <div class="entitlement-fields">
                            <template
                                v-for="item in group.items"
                                :key="item.key"
                                ><label
                                    v-if="item.type === 'number'"
                                    class="entitlement-number"
                                    >{{ item.label
                                    }}<input
                                        v-model="
                                            planForm.entitlements[item.key]
                                        "
                                        type="number"
                                        :min="item.min"
                                        :max="item.max"
                                        required
                                    /><small v-if="item.help">{{
                                        item.help
                                    }}</small></label
                                ><label v-else class="check entitlement-check"
                                    ><input
                                        v-model="
                                            planForm.entitlements[item.key]
                                        "
                                        type="checkbox"
                                        true-value="1"
                                        false-value="0"
                                    /><span
                                        ><b>{{ item.label }}</b
                                        ><small v-if="item.help">{{
                                            item.help
                                        }}</small></span
                                    ></label
                                ></template
                            >
                        </div>
                    </fieldset>
                </div>
            </section>
            <div class="modal-actions wide">
                <button type="button" class="button ghost" @click="modal = ''">
                    Abbrechen</button
                ><button class="button" :disabled="busy || translating">
                    Tarif speichern
                </button>
            </div>
        </form>
    </AdminModal>
</template>

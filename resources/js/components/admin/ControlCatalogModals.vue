<script setup lang="ts">
import AdminModal from "./AdminModal.vue";
const props = defineProps<{ ctx: any }>();
const {
    modal,
    phraseForm,
    lookups,
    choosePhraseVariation,
    savePhrase,
    busy,
    catalogKind,
    categoryForm,
    variationForm,
    templateForm,
    saveCatalog,
} = props.ctx;
</script>
<template>
    <AdminModal
        v-if="modal === 'phrase'"
        title="Geschäftsbegriff hinzufügen"
        @close="modal = ''"
        ><form class="modal-form" @submit.prevent="savePhrase">
            <label
                >Sprache<select v-model="phraseForm.locale">
                    <option value="de">Deutsch</option>
                    <option value="en">Englisch</option>
                    <option value="ru">Russisch</option>
                    <option value="uk">Ukrainisch</option>
                </select></label
            ><label
                >Variante<select
                    v-model.number="phraseForm.variation_id"
                    required
                    @change="choosePhraseVariation"
                >
                    <option :value="null">Bitte wählen</option>
                    <option
                        v-for="variation in lookups.variations"
                        :key="variation.id"
                        :value="variation.id"
                    >
                        {{
                            variation.name.de ||
                            variation.name.ru ||
                            variation.code
                        }}
                    </option>
                </select></label
            ><label>Begriff<input v-model="phraseForm.phrase" required /></label
            ><label
                >Gewichtung<input
                    v-model.number="phraseForm.weight"
                    type="number"
                    min="0.1"
                    max="5"
                    step="0.1"
            /></label>
            <div class="modal-actions">
                <button type="button" class="button ghost" @click="modal = ''">
                    Abbrechen</button
                ><button class="button" :disabled="busy">Hinzufügen</button>
            </div>
        </form></AdminModal
    >
    <AdminModal
        v-if="modal === 'catalog'"
        title="Vorlagen-Eintrag anlegen"
        wide
        @close="modal = ''"
        ><div class="type-tabs">
            <button
                v-for="kind in [
                    ['category', 'Kategorie'],
                    ['variation', 'Variante'],
                    ['template', 'Vorlage'],
                ]"
                :key="kind[0]"
                :class="{ active: catalogKind === kind[0] }"
                @click="catalogKind = kind[0]"
            >
                {{ kind[1] }}
            </button>
        </div>
        <form class="modal-form form-grid" @submit.prevent="saveCatalog">
            <template v-if="catalogKind === 'category'"
                ><label
                    >Code<input v-model="categoryForm.code" required /></label
                ><label
                    >Reihenfolge<input
                        v-model.number="categoryForm.sort_order"
                        type="number" /></label
                ><label
                    >Deutsch<input
                        v-model="categoryForm.name.de"
                        required /></label
                ><label
                    >Russisch<input
                        v-model="categoryForm.name.ru"
                        required /></label></template
            ><template v-if="catalogKind === 'variation'"
                ><label
                    >Kategorie<select
                        v-model.number="variationForm.category_id"
                        required
                    >
                        <option
                            v-for="category in lookups.categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name.de || category.code }}
                        </option>
                    </select></label
                ><label
                    >Code<input v-model="variationForm.code" required /></label
                ><label
                    >Deutsch<input
                        v-model="variationForm.name.de"
                        required /></label
                ><label
                    >Russisch<input
                        v-model="variationForm.name.ru"
                        required /></label
                ><label
                    >Vorlagen-Code<input
                        v-model="variationForm.template_code" /></label
                ><label
                    >Priorität<input
                        v-model.number="variationForm.priority"
                        type="number" /></label></template
            ><template v-if="catalogKind === 'template'"
                ><label
                    >Kategorie<select v-model.number="templateForm.category_id">
                        <option :value="null">Keine</option>
                        <option
                            v-for="category in lookups.categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name.de || category.code }}
                        </option>
                    </select></label
                ><label
                    >Variante<select v-model.number="templateForm.variation_id">
                        <option :value="null">Keine</option>
                        <option
                            v-for="variation in lookups.variations"
                            :key="variation.id"
                            :value="variation.id"
                        >
                            {{ variation.name.de || variation.code }}
                        </option>
                    </select></label
                ><label
                    >Code<input v-model="templateForm.code" required /></label
                ><label
                    >Übergeordneter Code<input
                        v-model="templateForm.parent_code" /></label
                ><label
                    >Version<input
                        v-model.number="templateForm.version"
                        type="number" /></label
                ><label
                    >Name Deutsch<input
                        v-model="templateForm.name.de"
                        required /></label
                ><label
                    >Name English<input v-model="templateForm.name.en" /></label
                ><label
                    >Name Русский<input v-model="templateForm.name.ru" /></label
                ><label
                    >Name Українська<input v-model="templateForm.name.uk"
                /></label>
                <div class="registry-context wide">
                    <span
                        >Nach dem Anlegen öffnet sich der visuelle Editor für
                        Bilder, Farben, Bildschirme, Blöcke, Formularfelder,
                        Foto-Schritte, Aktionen und KI-Begriffe.</span
                    >
                </div></template
            >
            <div class="modal-actions wide">
                <button type="button" class="button ghost" @click="modal = ''">
                    Abbrechen</button
                ><button class="button" :disabled="busy">Anlegen</button>
            </div>
        </form></AdminModal
    >
</template>

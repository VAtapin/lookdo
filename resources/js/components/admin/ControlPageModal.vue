<script setup lang="ts">
import AdminModal from "./AdminModal.vue";
import RichContentEditor from "./RichContentEditor.vue";
const props = defineProps<{ ctx: any }>();
const {
    modal,
    selectedPage,
    closePageEditor,
    savePage,
    translating,
    translatePage,
    pageTranslation,
    planLocales,
    contentLocale,
    busy,
} = props.ctx;
</script>
<template>
    <AdminModal
        v-if="modal === 'page' && selectedPage"
        title="Inhaltsseite bearbeiten"
        wide
        @close="closePageEditor"
        ><form class="modal-form" @submit.prevent="savePage">
            <div class="editor-head">
                <code>/{{ selectedPage.key }}</code
                ><label class="check"
                    ><input
                        v-model="selectedPage.is_published"
                        type="checkbox"
                    />
                    Veröffentlicht</label
                >
            </div>
            <div class="plan-section-head">
                <div>
                    <p>
                        Ausgangssprache wählen, Text schreiben und anschließend
                        die anderen drei Sprachen mit KI vorbereiten.
                    </p>
                </div>
                <button
                    type="button"
                    class="button small"
                    :disabled="translating"
                    @click="translatePage"
                >
                    {{ translating ? "KI übersetzt…" : "✦ Mit KI übersetzen" }}
                </button>
            </div>
            <div
                v-if="
                    pageTranslation.pageId === selectedPage.id &&
                    pageTranslation.phase === 'running'
                "
                class="translation-inline working"
            >
                <span>✦</span>
                <div>
                    <b>KI-Übersetzung läuft.</b
                    ><small
                        >Dieses Fenster können Sie schließen. Bitte den
                        Browser-Tab geöffnet lassen; das Ergebnis erscheint
                        anschließend oben in der Verwaltung.</small
                    >
                </div>
            </div>
            <div
                v-else-if="
                    pageTranslation.pageId === selectedPage.id &&
                    pageTranslation.phase === 'ready'
                "
                class="translation-inline ready"
            >
                <span>✓</span>
                <div>
                    <b>Übersetzung fertig.</b
                    ><small
                        >Die Texte wurden als Entwurf eingesetzt. Bitte prüfen
                        und anschließend speichern.</small
                    >
                </div>
            </div>
            <div
                v-else-if="
                    pageTranslation.pageId === selectedPage.id &&
                    pageTranslation.phase === 'error'
                "
                class="translation-inline failed"
            >
                <span>!</span>
                <div>
                    <b>Übersetzung fehlgeschlagen.</b
                    ><small>{{ pageTranslation.message }}</small>
                </div>
            </div>
            <div class="type-tabs">
                <button
                    v-for="locale in planLocales"
                    :key="locale[0]"
                    type="button"
                    :class="{ active: contentLocale === locale[0] }"
                    @click="contentLocale = locale[0]"
                >
                    {{ locale[1]
                    }}<span v-if="selectedPage.title[locale[0]]"> ✓</span>
                </button>
            </div>
            <label
                >Titel<input
                    v-model="selectedPage.title[contentLocale]" /></label
            ><label
                >Inhalt<RichContentEditor
                    v-model="selectedPage.content[contentLocale]"
            /></label>
            <p class="plan-translation-note">
                Das Bearbeitungsfenster darf während der Übersetzung geschlossen
                werden; der Browser-Tab muss geöffnet bleiben. Das Ergebnis wird
                als Entwurf eingesetzt und erst nach Ihrer Prüfung gespeichert.
                Platzhalter, Links und HTML bleiben erhalten.
            </p>
            <div class="modal-actions">
                <button
                    type="button"
                    class="button ghost"
                    @click="closePageEditor"
                >
                    Schließen</button
                ><button class="button" :disabled="busy || translating">
                    Speichern
                </button>
            </div>
        </form></AdminModal
    >
</template>

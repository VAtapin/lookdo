<script setup lang="ts">
const props = defineProps<{ ctx: any }>();
const { pageTranslation, openPageTranslationResult } = props.ctx;

function dismiss(): void {
    Object.assign(pageTranslation, {
        phase: "idle",
        pageId: null,
        pageKey: "",
        message: "",
    });
}
</script>

<template>
    <aside
        v-if="pageTranslation.phase !== 'idle'"
        class="translation-job"
        :class="pageTranslation.phase"
        role="status"
        aria-live="polite"
    >
        <span class="translation-job-icon">{{
            pageTranslation.phase === "ready"
                ? "✓"
                : pageTranslation.phase === "error"
                  ? "!"
                  : "✦"
        }}</span>
        <div>
            <b>{{
                pageTranslation.phase === "running"
                    ? `KI übersetzt /${pageTranslation.pageKey}`
                    : pageTranslation.phase === "ready"
                      ? `KI-Übersetzung für /${pageTranslation.pageKey} ist fertig`
                      : `KI-Übersetzung für /${pageTranslation.pageKey} ist fehlgeschlagen`
            }}</b>
            <small v-if="pageTranslation.phase === 'running'">
                Das Bearbeitungsfenster darf geschlossen werden. Bitte diesen
                Browser-Tab geöffnet lassen.
            </small>
            <small v-else>{{ pageTranslation.message }}</small>
        </div>
        <button
            v-if="pageTranslation.phase === 'ready'"
            type="button"
            @click="openPageTranslationResult"
        >
            Ergebnis öffnen
        </button>
        <button
            v-else-if="pageTranslation.phase === 'error'"
            type="button"
            @click="dismiss"
        >
            Ausblenden
        </button>
    </aside>
</template>

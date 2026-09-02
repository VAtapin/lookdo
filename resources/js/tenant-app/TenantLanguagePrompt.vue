<script setup lang="ts">
const labels: Record<string, string> = { de: "Deutsch", en: "English", ru: "Русский", uk: "Українська" };
defineProps<{ locales: string[]; current: string }>();
defineEmits<{ select: [locale: string]; close: [] }>();
</script>

<template>
    <div class="ta-language-prompt" role="dialog" aria-modal="true">
        <section>
            <button class="ta-language-close" aria-label="Close" @click="$emit('close')">×</button>
            <span>LANGUAGE · SPRACHE · ЯЗЫК · МОВА</span>
            <h2>Choose your language</h2>
            <p>Sprache wählen · Выберите язык · Оберіть мову</p>
            <div>
                <button v-for="entry in locales" :key="entry" :class="{ active: entry === current }" @click="$emit('select', entry)">
                    <b>{{ entry.toUpperCase() }}</b><strong>{{ labels[entry] || entry.toUpperCase() }}</strong>
                </button>
            </div>
        </section>
    </div>
</template>

<style>
.ta-language-trigger{min-width:40px!important;height:40px!important;padding:0 7px!important;border:1px solid currentColor!important;border-radius:12px!important;background:transparent!important;color:inherit!important;font-size:10px!important;font-weight:900!important;letter-spacing:.06em!important}
.ta-language-prompt{position:absolute;inset:0;z-index:45;display:grid;place-items:center;padding:20px;background:rgba(15,13,12,.68);backdrop-filter:blur(12px)}
.ta-language-prompt>section{position:relative;width:min(420px,100%);padding:27px 20px 20px;border:1px solid color-mix(in srgb,var(--ta-primary) 35%,#ddd);border-radius:24px;background:var(--ta-template-surface,#fff);color:var(--ta-template-text,#111318);box-shadow:0 24px 70px rgba(0,0,0,.28);text-align:center}
.ta-language-prompt>section>span{color:var(--ta-primary);font-size:10px;font-weight:900;letter-spacing:.12em}.ta-language-prompt h2{margin:9px 30px 5px;font-size:25px;line-height:1.1}.ta-language-prompt p{margin:0 0 18px;color:var(--ta-muted);font-size:13px}
.ta-language-prompt>section>div{display:grid;gap:9px}.ta-language-prompt>section>div button{display:grid;grid-template-columns:44px 1fr;align-items:center;min-height:55px;padding:6px 14px;border:1px solid var(--ta-line);border-radius:15px;background:#fff;color:#191a1e;text-align:left}.ta-language-prompt>section>div button.active{border-color:var(--ta-primary);box-shadow:0 0 0 2px color-mix(in srgb,var(--ta-primary) 12%,transparent)}
.ta-language-prompt>section>div b{color:var(--ta-primary);font-size:11px}.ta-language-prompt>section>div strong{font-size:15px}.ta-language-close{position:absolute;top:10px;right:10px;width:36px;height:36px;border:0;border-radius:11px;background:var(--ta-surface);font-size:24px;line-height:1}
</style>

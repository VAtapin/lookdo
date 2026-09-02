<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { api } from '../../api';

const props = defineProps<{ template: any; categories: any[]; variations: any[] }>();
const emit = defineEmits<{ close: []; saved: [template: any]; error: [message: string] }>();
const tabs = [
    ['basic', 'Grunddaten'], ['appearance', 'Darstellung & Startseite'], ['screens', 'Bildschirme & Blöcke'], ['fields', 'Formular'],
    ['media', 'Bilder & Video'], ['actions', 'Funktionen'], ['ai', 'KI & Sprachen'], ['advanced', 'Alle Einstellungen'], ['preview', 'Vorschau'],
];
const locales = ['de', 'en', 'ru', 'uk'];
const safeActions = [
    ['camera.open', 'Kamera öffnen'], ['media.library', 'Datei auswählen'], ['request.submit', 'Anfrage absenden'],
    ['booking.open', 'Termin buchen'], ['messages.open', 'Nachrichten öffnen'], ['payment.open', 'Zahlung öffnen'],
    ['customer.call', 'Kunden anrufen'], ['push.request', 'Push erlauben'],
];
const layoutOptions = [
    ['general', 'Universell'], ['brows', 'Beauty / Brows'], ['steering', 'Fahrzeug / Lenkrad'], ['doors', 'Montage / Türen'],
];
const navigationOptions = [
    ['home', 'Startseite'], ['works', 'Arbeiten'], ['services', 'Leistungen'], ['action', 'Hauptaktion'], ['book', 'Terminbuchung'],
    ['request', 'Anfrage'], ['activity', 'Aktivität / Nachrichten'], ['reviews', 'Bewertungen'], ['contacts', 'Kontakte'],
];
const capabilityOptions = [
    ['request', 'Visuelle Anfrage'], ['booking', 'Terminbuchung'], ['booking_primary', 'Terminbuchung als Hauptaktion'],
    ['services', 'Leistungen'], ['customers', 'Kundenverwaltung'], ['reminders', 'Erinnerungen'], ['repeat_visit', 'Wiederholungsbesuche'],
    ['vacancy_fill', 'Freie Termine füllen'], ['segments', 'Kundensegmente'], ['before_after', 'Vorher / Nachher'],
    ['ai_media', 'KI-Bildbearbeitung'], ['ai_communication', 'KI-Kommunikation'], ['social_content', 'Social Content'],
];
const fieldTypes = ['text', 'textarea', 'number', 'select', 'phone', 'email', 'checkbox', 'date', 'time'];
const blockTypes = ['hero', 'services', 'gallery', 'videos', 'trust', 'request-form', 'booking', 'how-it-works', 'reviews', 'before-after', 'messages', 'contact', 'custom-text'];
const activeTab = ref('basic');
const busy = ref(false);
const draggedBlock = ref<number | null>(null);
const advancedJson = ref('');
const advancedError = ref('');

const source = JSON.parse(JSON.stringify(props.template));
const configuration: any = source.configuration || {};
configuration.preview ||= { image: '', primary_color: '#ff6b00', secondary_color: '#25282e' };
configuration.engine ||= 'request';
configuration.layout ||= 'general';
configuration.theme ||= {};
configuration.theme.primary ||= configuration.preview.primary_color || '#ff6b00';
configuration.theme.secondary ||= configuration.preview.secondary_color || '#25282e';
configuration.theme.surface ||= '#ffffff';
configuration.theme.text ||= '#111318';
configuration.hero ||= {};
configuration.hero.image ||= configuration.preview.image || '';
for (const key of ['eyebrow', 'title', 'text', 'action']) configuration.hero[key] = localizedValue(configuration.hero[key]);
configuration.navigation = Array.isArray(configuration.navigation) ? configuration.navigation : ['home', 'works', 'action', 'activity', 'profile'];
configuration.capabilities ||= { request: true };
configuration.trust = Array.isArray(configuration.trust) ? configuration.trust : [];
for (const item of configuration.trust) item.label = localizedValue(item.label);
configuration.starter_portfolio = Array.isArray(configuration.starter_portfolio) ? configuration.starter_portfolio : [];
for (const item of configuration.starter_portfolio) item.title = localizedValue(item.title);
configuration.fields = Array.isArray(configuration.fields) ? configuration.fields : [];
configuration.media ||= {};
if (!Array.isArray(configuration.media.slots)) configuration.media.slots = Array.isArray(configuration.media_slots) ? configuration.media_slots : [];
configuration.media.video ||= configuration.video || { allowed: configuration.media.video_allowed ?? false, recommended: false, prominence: 'secondary' };
configuration.media.video.allowed = configuration.media.video.allowed ?? configuration.media.video_allowed ?? false;
configuration.actions = Array.isArray(configuration.actions) ? configuration.actions : [];
configuration.screens = Array.isArray(configuration.screens) && configuration.screens.length ? configuration.screens : [
    { key: 'home', name: 'Startseite', blocks: [
        { type: 'hero', title: textValue(configuration.title) || source.name?.de || source.code, enabled: true },
        { type: 'gallery', title: 'Arbeiten', enabled: true },
        { type: 'trust', title: 'Vorteile', enabled: true },
        { type: 'videos', title: 'Videos', enabled: true },
    ] },
];
configuration.locales = Array.isArray(configuration.locales) ? configuration.locales : ['de', 'en', 'ru', 'uk'];
configuration.ai_phrases ||= { de: [], en: [], ru: [], uk: [] };
if (Array.isArray(configuration.ai_phrases)) configuration.ai_phrases = { de: [], en: [], ru: configuration.ai_phrases, uk: [] };

const draft = reactive<any>({ ...source, name: { de: '', en: '', ru: '', uk: '', ...(source.name || {}) }, configuration });
const aiText = reactive<Record<string, string>>({
    de: (configuration.ai_phrases.de || []).join('\n'), en: (configuration.ai_phrases.en || []).join('\n'),
    ru: (configuration.ai_phrases.ru || []).join('\n'), uk: (configuration.ai_phrases.uk || []).join('\n'),
});
const selectedScreen = ref(0);
const filteredVariations = computed(() => props.variations.filter(item => !draft.category_id || item.category_id === Number(draft.category_id)));
const currentScreen = computed(() => draft.configuration.screens[selectedScreen.value] || draft.configuration.screens[0]);

function textValue(value: any): string {
    if (typeof value === 'string') return value;
    if (value && typeof value === 'object') return value.de || value.ru || value.en || value.uk || '';
    return '';
}
function localizedValue(value: any): Record<string, string> {
    if (value && typeof value === 'object' && !Array.isArray(value)) return { de: '', en: '', ru: '', uk: '', ...value };
    const text = typeof value === 'string' ? value : '';
    return { de: text, en: text, ru: text, uk: text };
}
function slug(value: string) { return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || `item-${Date.now()}`; }
function move(list: any[], index: string | number, direction: number) {
    const position = Number(index); const target = position + direction; if (target < 0 || target >= list.length) return;
    const [item] = list.splice(position, 1); list.splice(target, 0, item);
}
function addScreen() { draft.configuration.screens.push({ key: `screen-${draft.configuration.screens.length + 1}`, name: 'Neuer Bildschirm', blocks: [] }); selectedScreen.value = draft.configuration.screens.length - 1; }
function removeScreen(index: string | number) { if (draft.configuration.screens.length === 1) return; draft.configuration.screens.splice(Number(index), 1); selectedScreen.value = Math.max(0, selectedScreen.value - 1); }
function addBlock() { currentScreen.value.blocks.push({ type: 'custom-text', title: 'Neuer Block', enabled: true }); }
function dropBlock(index: string | number) { const target = Number(index); if (draggedBlock.value === null || draggedBlock.value === target) return; const [item] = currentScreen.value.blocks.splice(draggedBlock.value, 1); currentScreen.value.blocks.splice(target, 0, item); draggedBlock.value = null; }
function addField() { draft.configuration.fields.push({ key: `field_${draft.configuration.fields.length + 1}`, type: 'text', label: 'Neues Feld', placeholder: '', required: false, tenant_can_disable: true, options: [] }); }
function addMediaSlot() { draft.configuration.media.slots.push({ key: `photo_${draft.configuration.media.slots.length + 1}`, role: 'condition', title: 'Neues Foto', instruction: '', required: false, hint_image: '' }); }
function addAction() { draft.configuration.actions.push({ command: 'camera.open', label: 'Kamera öffnen', enabled: true }); }
function addOption(field: any) { field.options ||= []; field.options.push('Neue Option'); }
function addTrustItem() { draft.configuration.trust.push({ icon: 'star', label: localizedValue('Neuer Vorteil') }); }
function addPortfolioItem() { draft.configuration.starter_portfolio.push({ title: localizedValue('Neue Arbeit'), image: '', video: '', before_image: '', after_image: '', featured: false }); }
function toggleNavigation(key: string, enabled: boolean) {
    const list = draft.configuration.navigation as string[];
    const index = list.indexOf(key);
    if (enabled && index < 0) list.push(key);
    if (!enabled && index >= 0) list.splice(index, 1);
}
function setNavigation(key: string, event: Event) { toggleNavigation(key, Boolean((event.target as HTMLInputElement).checked)); }
function navigationEnabled(key: string) { return draft.configuration.navigation.includes(key); }
function moveNavigation(key: string, direction: number) {
    const index = draft.configuration.navigation.indexOf(key);
    if (index >= 0) move(draft.configuration.navigation, index, direction);
}

async function uploadMedia(event: Event, target: any, key: string) {
    const input = event.target as HTMLInputElement; const file = input.files?.[0]; if (!file) return;
    busy.value = true;
    try {
        const body = new FormData(); body.append('file', file);
        const result = await api<any>('/control/content-media', { method: 'POST', body }); target[key] = result.url;
    } catch (exception: any) { emit('error', exception.message); }
    finally { busy.value = false; input.value = ''; }
}

function selectTab(tab: string) {
    if (activeTab.value === 'advanced' && tab !== 'advanced' && !applyAdvanced()) return;
    if (tab === 'advanced') {
        advancedJson.value = JSON.stringify(draft.configuration, null, 2);
        advancedError.value = '';
    }
    activeTab.value = tab;
}
function applyAdvanced(): boolean {
    try {
        const parsed = JSON.parse(advancedJson.value);
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('Die Konfiguration muss ein JSON-Objekt sein.');
        draft.configuration = parsed;
        advancedError.value = '';
        return true;
    } catch (exception: any) {
        advancedError.value = `Ungültiges JSON: ${exception.message}`;
        return false;
    }
}

async function save() {
    busy.value = true;
    try {
        if (activeTab.value === 'advanced' && !applyAdvanced()) return;
        draft.configuration.media ||= {};
        draft.configuration.media.video ||= { allowed: false };
        draft.configuration.media.video_allowed = Boolean(draft.configuration.media.video.allowed);
        draft.configuration.capabilities ||= {};
        draft.configuration.capabilities.ui_builder_enabled = true;
        draft.configuration.preview ||= {};
        draft.configuration.preview.image ||= draft.configuration.hero?.image || '';
        draft.configuration.preview.primary_color = draft.configuration.theme?.primary || draft.configuration.preview.primary_color || '#ff6b00';
        draft.configuration.preview.secondary_color = draft.configuration.theme?.secondary || draft.configuration.preview.secondary_color || '#25282e';
        draft.configuration.ai_phrases ||= { de: [], en: [], ru: [], uk: [] };
        for (const locale of locales) draft.configuration.ai_phrases[locale] = aiText[locale].split('\n').map(item => item.trim()).filter(Boolean);
        const payload = {
            category_id: draft.category_id || null, variation_id: draft.variation_id || null, code: draft.code,
            parent_code: draft.parent_code || null, name: draft.name, configuration: draft.configuration,
            enabled: Boolean(draft.enabled), version: Number(draft.version || 1), sort_order: Number(draft.sort_order || 0),
        };
        const saved = await api<any>(`/control/templates/${draft.id}`, { method: 'PUT', body: JSON.stringify(payload) }); emit('saved', saved);
    } catch (exception: any) { emit('error', exception.message); }
    finally { busy.value = false; }
}

</script>

<template>
<div class="template-editor-shell">
    <header class="template-editor-head">
        <div><p class="eyebrow">VORLAGE #{{ draft.id }} · VERSION {{ draft.version }}</p><h2>{{ draft.name.de || draft.name.ru || draft.code }}</h2><code>{{ draft.code }}</code></div>
        <div class="template-editor-actions"><button type="button" class="button ghost small" @click="emit('close')">Schließen</button><button type="button" class="button small" :disabled="busy" @click="save">{{ busy ? 'Wird gespeichert…' : 'Vorlage speichern' }}</button></div>
    </header>

    <nav class="template-editor-tabs"><button v-for="tab in tabs" :key="tab[0]" type="button" :class="{ active: activeTab === tab[0] }" @click="selectTab(tab[0])">{{ tab[1] }}</button></nav>

    <section v-if="activeTab === 'basic'" class="template-editor-panel">
        <div class="template-editor-section-head"><div><p class="eyebrow">GRUNDDATEN</p><h3>Identität und Zuordnung</h3></div><label class="settings-toggle"><input v-model="draft.enabled" type="checkbox"><span><b>Vorlage aktiv</b><small>Kann neuen Kunden zugeordnet werden.</small></span></label></div>
        <div class="settings-form-grid"><label>Kategorie<select v-model.number="draft.category_id"><option :value="null">Keine</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name?.de || category.code }}</option></select></label><label>Variante<select v-model.number="draft.variation_id"><option :value="null">Keine</option><option v-for="variation in filteredVariations" :key="variation.id" :value="variation.id">{{ variation.name?.de || variation.code }}</option></select></label><label>Code<input v-model="draft.code" required></label><label>Übergeordnete Vorlage<input v-model="draft.parent_code" placeholder="z. B. automotive.general"></label><label>Version<input v-model.number="draft.version" type="number" min="1"></label><label>Reihenfolge<input v-model.number="draft.sort_order" type="number" min="0"></label></div>
        <div class="template-language-grid"><label v-for="locale in ['de','en','ru','uk']" :key="locale">Name {{ locale.toUpperCase() }}<input v-model="draft.name[locale]"></label></div>
    </section>

    <section v-if="activeTab === 'appearance'" class="template-editor-panel">
        <div class="template-editor-section-head"><div><p class="eyebrow">DARSTELLUNG</p><h3>Layout, Startseite und Farben</h3><p>Hier wird die tatsächlich verwendete Ausgangsdarstellung der Kunden-App bearbeitet. Bereits vom Kunden hochgeladene Markenbilder werden nicht überschrieben.</p></div></div>
        <div class="template-appearance-grid"><div class="template-image-control"><img decoding="async" v-if="draft.configuration.hero.image" :src="draft.configuration.hero.image" alt="Startbild"><div v-else class="template-image-empty">Kein Startbild</div><label class="media-file-button"><input type="file" accept="image/jpeg,image/png,image/webp,image/svg+xml" :disabled="busy" @change="uploadMedia($event, draft.configuration.hero, 'image')">Startbild hochladen</label><button v-if="draft.configuration.hero.image" type="button" class="button ghost small" @click="draft.configuration.hero.image = ''">Startbild entfernen</button></div><div class="settings-form-grid"><label>Layout<select v-model="draft.configuration.layout"><option v-for="item in layoutOptions" :key="item[0]" :value="item[0]">{{ item[1] }}</option></select></label><label>Hauptablauf<select v-model="draft.configuration.engine"><option value="request">Visuelle Anfrage</option><option value="booking">Terminbuchung</option><option value="hybrid">Anfrage + Termin</option></select></label><label>Startbild-URL<input v-model="draft.configuration.hero.image"></label><label>Vorschaubild-URL<input v-model="draft.configuration.preview.image"></label><label>Primärfarbe<span class="color-input"><input v-model="draft.configuration.theme.primary"><input v-model="draft.configuration.theme.primary" type="color"></span></label><label>Sekundärfarbe<span class="color-input"><input v-model="draft.configuration.theme.secondary"><input v-model="draft.configuration.theme.secondary" type="color"></span></label><label>Flächenfarbe<span class="color-input"><input v-model="draft.configuration.theme.surface"><input v-model="draft.configuration.theme.surface" type="color"></span></label><label>Textfarbe<span class="color-input"><input v-model="draft.configuration.theme.text"><input v-model="draft.configuration.theme.text" type="color"></span></label></div></div>
        <div class="template-copy-editor"><article v-for="locale in locales" :key="locale"><h4>Startseite {{ locale.toUpperCase() }}</h4><label>Kurzbezeichnung<input v-model="draft.configuration.hero.eyebrow[locale]"></label><label>Überschrift<input v-model="draft.configuration.hero.title[locale]"></label><label>Beschreibung<textarea v-model="draft.configuration.hero.text[locale]" rows="4"></textarea></label><label>Hauptbutton<input v-model="draft.configuration.hero.action[locale]"></label></article></div>
    </section>

    <section v-if="activeTab === 'screens'" class="template-editor-panel">
        <div class="template-editor-section-head"><div><p class="eyebrow">APP-AUFBAU</p><h3>Bildschirme und Blockreihenfolge</h3><p>Blöcke können gezogen oder mit den Pfeilen verschoben werden.</p></div><button type="button" class="button ghost small" @click="addScreen">＋ Bildschirm</button></div>
        <div class="screen-builder"><aside><button v-for="(screen,index) in draft.configuration.screens" :key="screen.key" type="button" :class="{active:selectedScreen===index}" @click="selectedScreen=Number(index)"><span><b>{{ screen.name }}</b><small>{{ screen.key }}</small></span><em v-if="draft.configuration.screens.length>1" @click.stop="removeScreen(index)">×</em></button></aside><div class="screen-canvas"><div class="settings-form-grid"><label>Bildschirmname<input v-model="currentScreen.name"></label><label>Technischer Schlüssel<input v-model="currentScreen.key"></label></div><div class="block-list"><article v-for="(block,index) in currentScreen.blocks" :key="index" draggable="true" @dragstart="draggedBlock=Number(index)" @dragover.prevent @drop="dropBlock(index)"><span class="drag-handle">⋮⋮</span><label>Blocktyp<select v-model="block.type"><option v-for="type in blockTypes" :key="type" :value="type">{{ type }}</option></select></label><label>Titel<input v-model="block.title"></label><label class="check"><input v-model="block.enabled" type="checkbox"> Sichtbar</label><div class="row-actions"><button type="button" @click="move(currentScreen.blocks,index,-1)">↑</button><button type="button" @click="move(currentScreen.blocks,index,1)">↓</button><button type="button" class="danger" @click="currentScreen.blocks.splice(Number(index),1)">×</button></div></article></div><button type="button" class="button ghost small" @click="addBlock">＋ Block hinzufügen</button></div></div>
    </section>

    <section v-if="activeTab === 'fields'" class="template-editor-panel">
        <div class="template-editor-section-head"><div><p class="eyebrow">FORMULAR</p><h3>Felder der Kundenanfrage</h3></div><button type="button" class="button ghost small" @click="addField">＋ Feld</button></div>
        <div class="structured-list"><article v-for="(field,index) in draft.configuration.fields" :key="index"><header><b>{{ field.label || field.key }}</b><div class="row-actions"><button type="button" @click="move(draft.configuration.fields,index,-1)">↑</button><button type="button" @click="move(draft.configuration.fields,index,1)">↓</button><button type="button" class="danger" @click="draft.configuration.fields.splice(Number(index),1)">Löschen</button></div></header><div class="settings-form-grid"><label>Schlüssel<input v-model="field.key"></label><label>Typ<select v-model="field.type"><option v-for="type in fieldTypes" :key="type">{{ type }}</option></select></label><label>Bezeichnung<input v-model="field.label"></label><label>Platzhalter<input v-model="field.placeholder"></label><label class="check"><input v-model="field.required" type="checkbox"> Pflichtfeld</label><label class="check"><input v-model="field.tenant_can_disable" type="checkbox"> Kunde darf deaktivieren</label></div><div v-if="field.type==='select'" class="field-options"><label v-for="(_,optionIndex) in field.options || []" :key="optionIndex">Option {{ Number(optionIndex)+1 }}<span><input v-model="field.options[optionIndex]"><button type="button" @click="field.options.splice(Number(optionIndex),1)">×</button></span></label><button type="button" class="button ghost small" @click="addOption(field)">＋ Option</button></div></article></div>
    </section>

    <section v-if="activeTab === 'media'" class="template-editor-panel">
        <div class="template-editor-section-head"><div><p class="eyebrow">BILDER & VIDEO</p><h3>Uploads, visuelle Hinweise und Startinhalte</h3><p>Startinhalte werden nur bei neu angelegten oder noch leeren Kundenbereichen erzeugt. Bestehende Arbeiten eines Kunden bleiben unangetastet.</p></div></div>
        <div class="template-media-switches"><label class="settings-toggle compact"><input v-model="draft.configuration.media.video.allowed" type="checkbox"><span><b>Video erlauben</b><small>Endkunden dürfen ein Video mitsenden.</small></span></label><label class="settings-toggle compact"><input v-model="draft.configuration.media.video.recommended" type="checkbox"><span><b>Video empfehlen</b><small>Video im Upload-Ablauf sichtbar hervorheben.</small></span></label><label>Darstellung<select v-model="draft.configuration.media.video.prominence"><option value="secondary">Sekundär</option><option value="primary">Primär</option><option value="equal">Wie Fotos</option></select></label></div>
        <div class="template-editor-section-head"><div><h3>Foto- und Upload-Schritte</h3></div><button type="button" class="button ghost small" @click="addMediaSlot">＋ Upload-Schritt</button></div>
        <div class="media-slot-grid"><article v-for="(slot,index) in draft.configuration.media.slots" :key="index"><div class="media-slot-preview"><img loading="lazy" decoding="async" v-if="slot.hint_image" :src="slot.hint_image" alt="Hinweis"><span v-else>HINWEIS</span></div><div class="settings-form-grid"><label>Schlüssel<input v-model="slot.key"></label><label>Rolle<input v-model="slot.role"></label><label>Medientyp<select v-model="slot.type"><option value="image">Foto</option><option value="video">Video</option><option value="media">Foto oder Video</option></select></label><label>Titel<input v-model="slot.title"></label><label class="check"><input v-model="slot.required" type="checkbox"> Pflichtaufnahme</label><label class="wide">Anweisung<textarea v-model="slot.instruction" rows="3"></textarea></label><label>Hinweisbild<input v-model="slot.hint_image"></label><label class="media-file-button">Bild hochladen<input type="file" accept="image/*" :disabled="busy" @change="uploadMedia($event, slot, 'hint_image')"></label><button v-if="slot.hint_image" type="button" class="button ghost small" @click="slot.hint_image = ''">Hinweisbild entfernen</button></div><div class="row-actions"><button type="button" @click="move(draft.configuration.media.slots,index,-1)">↑</button><button type="button" @click="move(draft.configuration.media.slots,index,1)">↓</button><button type="button" class="danger" @click="draft.configuration.media.slots.splice(Number(index),1)">Löschen</button></div></article></div>
        <div class="template-editor-section-head"><div><h3>Start-Arbeiten im neuen Kundenkonto</h3><p>Foto, Video und Vorher/Nachher können einzeln oder kombiniert hinterlegt werden.</p></div><button type="button" class="button ghost small" @click="addPortfolioItem">＋ Arbeit</button></div>
        <div class="template-portfolio-grid"><article v-for="(item,index) in draft.configuration.starter_portfolio" :key="index"><header><b>{{ textValue(item.title) || `Arbeit ${Number(index)+1}` }}</b><div class="row-actions"><button type="button" @click="move(draft.configuration.starter_portfolio,index,-1)">↑</button><button type="button" @click="move(draft.configuration.starter_portfolio,index,1)">↓</button><button type="button" class="danger" @click="draft.configuration.starter_portfolio.splice(Number(index),1)">Löschen</button></div></header><div class="template-language-grid compact"><label v-for="locale in locales" :key="locale">Titel {{ locale.toUpperCase() }}<input v-model="item.title[locale]"></label></div><label class="check"><input v-model="item.featured" type="checkbox"> Auf der Startseite hervorheben</label><div class="template-portfolio-assets"><div v-for="asset in [['image','Foto'],['video','Video'],['before_image','Vorher'],['after_image','Nachher']]" :key="asset[0]" class="template-asset-control"><b>{{ asset[1] }}</b><video v-if="asset[0] === 'video' && item[asset[0]]" :src="item[asset[0]]" controls preload="metadata"></video><img v-else-if="item[asset[0]]" :src="item[asset[0]]" :alt="asset[1]"><span v-else>Keine Datei</span><input v-model="item[asset[0]]" :placeholder="`${asset[1]}-URL`"><label class="media-file-button"><input type="file" :accept="asset[0] === 'video' ? 'video/mp4,video/webm,video/quicktime' : 'image/*'" :disabled="busy" @change="uploadMedia($event, item, asset[0])">{{ asset[1] }} hochladen</label><button v-if="item[asset[0]]" type="button" class="button ghost small" @click="item[asset[0]] = ''">Entfernen</button></div></div></article></div>
    </section>

    <section v-if="activeTab === 'actions'" class="template-editor-panel">
        <div class="template-editor-section-head"><div><p class="eyebrow">FUNKTIONEN</p><h3>Module und Fähigkeiten</h3><p>Diese Schalter definieren, welche Plattformfunktionen die Vorlage grundsätzlich unterstützt. Tarifgrenzen gelten zusätzlich.</p></div></div>
        <div class="template-capability-grid"><label v-for="item in capabilityOptions" :key="item[0]" class="settings-toggle compact"><input v-model="draft.configuration.capabilities[item[0]]" type="checkbox"><span><b>{{ item[1] }}</b><small>{{ item[0] }}</small></span></label></div>
        <div class="template-editor-section-head"><div><h3>Navigation und Reihenfolge</h3><p>Aktive Punkte lassen sich mit den Pfeilen in der tatsächlichen Menü-Reihenfolge verschieben.</p></div></div>
        <div class="template-navigation-list"><article v-for="item in navigationOptions" :key="item[0]" :class="{active:navigationEnabled(item[0])}"><label class="check"><input type="checkbox" :checked="navigationEnabled(item[0])" @change="setNavigation(item[0], $event)"> {{ item[1] }}</label><code>{{ item[0] }}</code><div class="row-actions"><button type="button" :disabled="!navigationEnabled(item[0])" @click="moveNavigation(item[0],-1)">↑</button><button type="button" :disabled="!navigationEnabled(item[0])" @click="moveNavigation(item[0],1)">↓</button></div></article></div>
        <div class="template-editor-section-head"><div><h3>Vorteile auf der Startseite</h3></div><button type="button" class="button ghost small" @click="addTrustItem">＋ Vorteil</button></div>
        <div class="structured-list"><article v-for="(item,index) in draft.configuration.trust" :key="index"><header><b>{{ textValue(item.label) || 'Vorteil' }}</b><div class="row-actions"><button type="button" @click="move(draft.configuration.trust,index,-1)">↑</button><button type="button" @click="move(draft.configuration.trust,index,1)">↓</button><button type="button" class="danger" @click="draft.configuration.trust.splice(Number(index),1)">Löschen</button></div></header><label>Symbol<input v-model="item.icon" placeholder="star, shield, clock, heart …"></label><div class="template-language-grid compact"><label v-for="locale in locales" :key="locale">Text {{ locale.toUpperCase() }}<input v-model="item.label[locale]"></label></div></article></div>
        <div class="template-editor-section-head"><div><h3>Schaltflächen und Systemaktionen</h3><p>Es sind ausschließlich geprüfte Plattformaktionen auswählbar. Freier Programmcode ist nicht möglich.</p></div><button type="button" class="button ghost small" @click="addAction">＋ Aktion</button></div>
        <div class="structured-list action-list"><article v-for="(action,index) in draft.configuration.actions" :key="index"><div class="settings-form-grid"><label>Systemaktion<select v-model="action.command"><option v-for="item in safeActions" :key="item[0]" :value="item[0]">{{ item[1] }}</option></select></label><label>Beschriftung<input v-model="action.label"></label><label class="check"><input v-model="action.enabled" type="checkbox"> Aktiv</label></div><div class="row-actions"><button type="button" @click="move(draft.configuration.actions,index,-1)">↑</button><button type="button" @click="move(draft.configuration.actions,index,1)">↓</button><button type="button" class="danger" @click="draft.configuration.actions.splice(Number(index),1)">Löschen</button></div></article></div>
    </section>

    <section v-if="activeTab === 'ai'" class="template-editor-panel">
        <div class="template-editor-section-head"><div><p class="eyebrow">KI & SPRACHEN</p><h3>Erkennung und verfügbare Sprachen</h3><p>Je Zeile ein Begriff, anhand dessen diese Vorlage vorgeschlagen wird.</p></div></div>
        <div class="template-language-grid"><label v-for="locale in locales" :key="locale">KI-Begriffe {{ locale.toUpperCase() }}<textarea v-model="aiText[locale]" rows="9"></textarea></label></div>
        <fieldset class="template-locales"><legend>Sprachen dieser Vorlage</legend><label v-for="locale in locales" :key="locale" class="check"><input v-model="draft.configuration.locales" type="checkbox" :value="locale"> {{ locale.toUpperCase() }}</label></fieldset>
    </section>

    <section v-if="activeTab === 'advanced'" class="template-editor-panel">
        <div class="template-editor-section-head"><div><p class="eyebrow">ALLE EINSTELLUNGEN</p><h3>Vollständige Vorlagen-Konfiguration</h3><p>Hier ist ausnahmslos die gesamte Konfiguration sichtbar, einschließlich neuer oder spezieller Funktionen, die noch kein eigenes Formularfeld besitzen. Änderungen werden vor dem Speichern auf gültiges JSON geprüft.</p></div><button type="button" class="button ghost small" @click="applyAdvanced">JSON übernehmen</button></div>
        <p v-if="advancedError" class="alert error">{{ advancedError }}</p>
        <textarea v-model="advancedJson" class="template-json-editor" spellcheck="false" aria-label="Vollständige JSON-Konfiguration"></textarea>
    </section>

    <section v-if="activeTab === 'preview'" class="template-editor-panel">
        <div class="template-editor-section-head"><div><p class="eyebrow">LIVE-VORSCHAU</p><h3>Mobile Ausgangsdarstellung</h3><p>Vorschau der Startseite mit dem aktuell gewählten Bild, den Farben und Blöcken.</p></div></div>
        <div class="template-phone-preview" :style="{'--template-primary':draft.configuration.theme?.primary||'#ff6b00','--template-secondary':draft.configuration.theme?.secondary||'#25282e'}"><div class="template-phone-island"></div><div class="template-phone-screen"><header><b>{{ draft.name.de || draft.name.ru }}</b><span>⌁</span></header><img decoding="async" v-if="draft.configuration.hero?.image" :src="draft.configuration.hero.image" alt="Startbild"><h3>{{ textValue(draft.configuration.hero?.title) || draft.name.de || draft.code }}</h3><div class="preview-blocks"><span v-for="block in (currentScreen?.blocks || []).filter((item:any)=>item.enabled!==false)" :key="block.type"><b>{{ block.title || block.type }}</b><small>{{ block.type }}</small></span></div><button type="button">{{ textValue(draft.configuration.hero?.action) || draft.configuration.actions?.find((item:any)=>item.enabled)?.label || (draft.configuration.engine==='booking'?'Termin buchen':'Anfrage senden') }}</button></div></div>
    </section>

    <footer class="template-editor-footer"><span>Produktiv verwendete Vorlagen werden deaktiviert statt physisch gelöscht.</span><button type="button" class="button" :disabled="busy" @click="save">{{ busy ? 'Wird gespeichert…' : 'Änderungen speichern' }}</button></footer>
</div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../../api';

const props = defineProps<{ data: any }>();
const route = useRoute();
const router = useRouter();
const busy = ref(false);
const pushBusy = ref(false);
const pushStatus = ref('');
const adminPushDevices = ref(0);
const error = ref('');
const notice = ref('');
const persistedSmsEnabled = computed(() => Boolean(props.data?.settings?.integrations?.sms));
const persistedOpenAiAdminKey = computed(() => Boolean(props.data?.openai?.admin_key_configured));
const locales = [['de', 'Deutsch'], ['en', 'English'], ['ru', 'Русский'], ['uk', 'Українська']];
const allowedGroups = ['legal', 'platform', 'media', 'notifications', 'sms', 'openai', 'operation'];
const group = computed(() => {
    const value = String(route.params.settingsGroup || '');
    return allowedGroups.includes(value) ? value : '';
});

const form = reactive<any>({
    platform_name: 'LOOKDO', support_email: '', default_locale: 'ru', default_request_template_code: 'general-services.general',
    trial_days_default: 0, upload_base_limit_mb: 100,
    social_share_image_url: '',
    social_share_images: { de: '/brand/lookdo-social-de.jpg', en: '/brand/lookdo-social-en.jpg', ru: '/brand/lookdo-social-ru.jpg', uk: '/brand/lookdo-social-uk.jpg' },
    demo_video_source: 'none', demo_video_url: '', registration_enabled: true, maintenance: false,
    enabled_locales: ['de', 'en', 'ru', 'uk'], integrations: { stripe: true, openai: true, sms: false },
    sms_provider: 'seven', sms_sender: 'LOOKDO', sms_events: { request_received: true, master_replied: true, work_ready: true, agreement_reminder: true },
    admin_notifications: { push: false, email: false, sms: false },
    admin_notification_email: '', admin_notification_phone: '', admin_notification_sms_monthly_limit: 100,
    sms_seven_api_key: '', sms_seven_signing_key: '', sms_clear_api_key: false, sms_clear_signing_key: false,
    openai_project_id: '', openai_admin_key: '', openai_clear_admin_key: false,
    legal_operator_name: '', legal_operator_address: '', legal_representative: '', legal_email: '', legal_phone: '', legal_register: '', legal_vat_id: '',
});

function hydrate() {
    const settings = props.data?.settings || {};
    Object.assign(form, settings, {
        social_share_images: { de: '/brand/lookdo-social-de.jpg', en: '/brand/lookdo-social-en.jpg', ru: '/brand/lookdo-social-ru.jpg', uk: '/brand/lookdo-social-uk.jpg', ...(settings.social_share_images || {}) },
        integrations: { stripe: true, openai: true, sms: false, ...(settings.integrations || {}) },
        sms_events: { request_received: true, master_replied: true, work_ready: true, agreement_reminder: true, ...(settings.sms_events || {}) },
        admin_notifications: { push: false, email: false, sms: false, ...(settings.admin_notifications || {}) },
        sms_seven_api_key: '', sms_seven_signing_key: '', sms_clear_api_key: false, sms_clear_signing_key: false,
        openai_admin_key: '', openai_clear_admin_key: false,
    });
    adminPushDevices.value = Number(props.data?.notifications?.push_subscriptions || 0);
}
watch(() => props.data, hydrate, { immediate: true, deep: true });

const sections = computed(() => [
    { key: 'legal', icon: '§', title: 'Rechtliche Angaben und Kontakte', description: 'Betreiber, Anschrift, Kontakt, Register und USt-IdNr.', status: form.legal_operator_name && form.legal_email ? 'Eingerichtet' : 'Unvollständig', tone: form.legal_operator_name && form.legal_email ? 'ready' : 'warning' },
    { key: 'platform', icon: '⚙', title: 'Allgemeine Plattformparameter', description: 'Name, Standardsprache, Standardvorlage, Testtage und Upload-Limit.', status: form.default_request_template_code ? 'Eingerichtet' : 'Unvollständig', tone: form.default_request_template_code ? 'ready' : 'warning' },
    { key: 'media', icon: '▣', title: 'Freigabe, Vorschaubilder und Demo', description: 'Vier Sprachbilder für WhatsApp und soziale Netzwerke sowie das Demo-Video.', status: `${locales.filter(([code]) => form.social_share_images?.[code]).length}/4 Bilder`, tone: 'ready' },
    { key: 'notifications', icon: '●', title: 'Admin-Benachrichtigungen', description: 'Neue Kundenanfragen per Push, E-Mail und SMS direkt an den Super Administrator melden.', status: Object.values(form.admin_notifications || {}).some(Boolean) ? 'Aktiv' : 'Deaktiviert', tone: Object.values(form.admin_notifications || {}).some(Boolean) ? 'ready' : 'muted' },
    { key: 'sms', icon: '✉', title: 'SMS-Gateway', description: 'Provider, Schlüssel, Absender, Zustellberichte und wichtige Ereignisse.', status: form.integrations.sms ? 'Aktiv' : 'Deaktiviert', tone: form.integrations.sms ? 'ready' : 'muted' },
    { key: 'openai', icon: '✦', title: 'OpenAI-Verbrauch und Kosten', description: 'Offizielle Organisationskosten mit dem lokalen Verbrauch pro Kunde abgleichen.', status: persistedOpenAiAdminKey.value ? 'Verbunden' : 'Nicht verbunden', tone: persistedOpenAiAdminKey.value ? 'ready' : 'warning' },
    { key: 'operation', icon: '◉', title: 'Betrieb, Integrationen und Sprachen', description: 'Registrierung, Wartungsmodus, Stripe, OpenAI, SMS und Plattformsprachen.', status: form.maintenance ? 'Wartungsmodus' : 'Online', tone: form.maintenance ? 'warning' : 'ready' },
]);

function showNotice(message: string) {
    notice.value = message;
    window.setTimeout(() => notice.value = '', 4000);
}

async function save() {
    busy.value = true; error.value = '';
    try {
        const result = await api<any>('/control/settings', { method: 'PUT', body: JSON.stringify({ settings: form }) });
        Object.assign(props.data, result);
        hydrate();
        showNotice('Einstellungen wurden gespeichert.');
    } catch (exception: any) { error.value = exception.message; }
    finally { busy.value = false; }
}

async function uploadMedia(event: Event, kind: 'image' | 'video', localeCode = 'de') {
    const input = event.target as HTMLInputElement; const file = input.files?.[0]; if (!file) return;
    busy.value = true; error.value = '';
    try {
        const body = new FormData(); body.append('file', file);
        const result = await api<any>('/control/content-media', { method: 'POST', body });
        if (kind === 'image') { form.social_share_images[localeCode] = result.url; form.social_share_image_url = ''; }
        else { form.demo_video_source = 'upload'; form.demo_video_url = result.url; }
        showNotice(kind === 'image' ? `Vorschaubild ${localeCode.toUpperCase()} wurde hochgeladen. Bitte speichern.` : 'Demo-Video wurde hochgeladen. Bitte speichern.');
    } catch (exception: any) { error.value = exception.message; }
    finally { busy.value = false; input.value = ''; }
}

async function testSms() {
    if (!persistedSmsEnabled.value) {
        error.value = 'SMS-Versand zuerst aktivieren und die Einstellungen speichern.';
        return;
    }
    busy.value = true; error.value = '';
    try {
        const result = await api<any>('/control/sms/test', { method: 'POST' });
        showNotice(`seven.io verbunden · Guthaben ${Number(result.balance.amount).toFixed(2)} ${result.balance.currency}`);
    } catch (exception: any) { error.value = exception.message; }
    finally { busy.value = false; }
}

async function testOpenAi() {
    if (!persistedOpenAiAdminKey.value) {
        error.value = 'OpenAI Admin Key zuerst speichern.';
        return;
    }
    busy.value = true; error.value = '';
    try {
        const result = await api<any>('/control/openai/test', { method: 'POST' });
        showNotice(`OpenAI verbunden · ${Number(result.month_cost || 0).toFixed(4)} ${String(result.currency || 'usd').toUpperCase()} in diesem Monat`);
    } catch (exception: any) { error.value = exception.message; }
    finally { busy.value = false; }
}

function applicationServerKey(value: string): Uint8Array {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(base64), character => character.charCodeAt(0));
}

async function syncAdminPush(enabled: boolean) {
    pushStatus.value = '';
    if (!props.data?.notifications?.push_configured || !('Notification' in window) || !('serviceWorker' in navigator)) {
        pushStatus.value = 'Push ist auf diesem Server oder Browser nicht verfügbar.';
        return;
    }
    pushBusy.value = true;
    try {
        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();
        if (!enabled) {
            if (subscription) {
                const result = await api<any>('/control/push-subscriptions', { method: 'DELETE', body: JSON.stringify({ endpoint: subscription.endpoint }) });
                adminPushDevices.value = Number(result.subscriptions || 0);
            }
            pushStatus.value = 'Push wurde für diesen Browser deaktiviert.';
            return;
        }
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            pushStatus.value = 'Der Browser hat Push-Benachrichtigungen nicht erlaubt.';
            return;
        }
        subscription ||= await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey(props.data.notifications.push_public_key) as BufferSource,
        });
        const json = subscription.toJSON();
        const result = await api<any>('/control/push-subscriptions', { method: 'POST', body: JSON.stringify({ endpoint: subscription.endpoint, keys: json.keys }) });
        adminPushDevices.value = Number(result.subscriptions || 1);
        form.admin_notifications.push = true;
        pushStatus.value = 'Dieser Browser ist registriert. Bitte Einstellungen speichern.';
    } catch (exception: any) {
        pushStatus.value = exception.message || 'Push konnte nicht eingerichtet werden.';
    } finally {
        pushBusy.value = false;
    }
}
</script>

<template>
<section class="human-settings">
    <p v-if="error" class="alert error">{{ error }}</p>
    <p v-if="notice" class="admin-toast">{{ notice }}</p>

    <section v-if="!group" class="settings-directory registry-page">
        <div class="registry-context"><span>Jeder Bereich wird auf einer eigenen Seite bearbeitet. Dadurch bleibt die Verwaltung übersichtlich.</span></div>
        <div class="admin-table-wrap">
            <table>
                <thead><tr><th>Bereich</th><th>Beschreibung</th><th>Status</th><th>Aktion</th></tr></thead>
                <tbody>
                    <tr v-for="item in sections" :key="item.key" class="clickable" @click="router.push(`/control/settings/${item.key}`)">
                        <td><span class="settings-directory-name"><i>{{ item.icon }}</i><b>{{ item.title }}</b></span></td>
                        <td>{{ item.description }}</td>
                        <td><span class="settings-section-status" :class="item.tone">{{ item.status }}</span></td>
                        <td class="table-actions"><button type="button" @click.stop="router.push(`/control/settings/${item.key}`)">Öffnen →</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <form v-else @submit.prevent="save">
        <div class="settings-section-toolbar">
            <RouterLink to="/control/settings">← Alle Einstellungen</RouterLink>
            <span>{{ sections.find(item => item.key === group)?.title }}</span>
        </div>

        <section v-if="group === 'legal'" class="settings-panel">
            <div class="settings-panel-head"><p class="eyebrow">BETREIBER & RECHTLICHES</p><h2>Rechtliche Angaben und Kontakte</h2><p>Diese Angaben werden automatisch in Impressum, Datenschutz und Kontakt eingesetzt.</p></div>
            <div class="settings-form-grid"><label>Unternehmen / Betreiber<input v-model="form.legal_operator_name"></label><label>Vertretungsberechtigte Person<input v-model="form.legal_representative"></label><label class="wide">Anschrift<textarea v-model="form.legal_operator_address" rows="3"></textarea></label><label>E-Mail<input v-model="form.legal_email" type="email"></label><label>Telefon<input v-model="form.legal_phone" type="tel"></label><label>Handelsregister<input v-model="form.legal_register"></label><label>USt-IdNr.<input v-model="form.legal_vat_id"></label></div>
        </section>

        <section v-if="group === 'platform'" class="settings-panel">
            <div class="settings-panel-head"><p class="eyebrow">PLATTFORM</p><h2>Allgemeine Parameter</h2><p>Grundwerte der Plattform ohne technische Schlüssel.</p></div>
            <div class="settings-form-grid"><label>Plattformname<input v-model="form.platform_name" required></label><label>Support-E-Mail<input v-model="form.support_email" type="email"></label><label>Standardsprache<select v-model="form.default_locale"><option v-for="locale in locales" :key="locale[0]" :value="locale[0]">{{ locale[1] }}</option></select></label><label>Standardvorlage<select v-model="form.default_request_template_code"><option v-for="template in data.templates" :key="template.code" :value="template.code">{{ template.name?.de || template.code }}</option></select></label><label>Standard-Testtage<input v-model.number="form.trial_days_default" type="number" min="0" max="365"></label><label>Upload-Limit pro Datei (MB)<input v-model.number="form.upload_base_limit_mb" type="number" min="1" max="2048"></label></div>
        </section>

        <section v-if="group === 'media'" class="settings-panel media-settings">
            <div class="settings-panel-head"><p class="eyebrow">FREIGABE & DEMO</p><h2>Vorschaubilder und Demo-Video</h2><p>Für jede Sprache wird ein eigenes Bild beim Teilen von lookdo.app verwendet.</p></div>
            <div class="platform-media-grid">
                <article class="social-preview-settings"><h3>Social-Media-Vorschaubilder</h3><p>Empfohlen: Querformat 1200 × 630 Pixel, JPG, PNG oder WebP.</p><div class="platform-social-grid"><figure v-for="item in locales" :key="item[0]"><figcaption>{{ item[1] }}</figcaption><img loading="lazy" decoding="async" :src="form.social_share_images[item[0]]" :alt="`Vorschaubild ${item[1]}`"><label class="media-file-button"><input type="file" accept="image/jpeg,image/png,image/webp" :disabled="busy" @change="uploadMedia($event, 'image', item[0])"><span>{{ busy ? 'Wird hochgeladen…' : 'Bild ersetzen' }}</span></label></figure></div></article>
                <article><h3>Demo-Video</h3><label>Quelle<select v-model="form.demo_video_source"><option value="none">Kein Video</option><option value="upload">Hochgeladene Datei</option><option value="youtube">YouTube</option></select></label><label v-if="form.demo_video_source === 'youtube'">YouTube-URL<input v-model="form.demo_video_url" placeholder="https://www.youtube.com/watch?v=…"></label><template v-if="form.demo_video_source === 'upload'"><video v-if="form.demo_video_url" :src="form.demo_video_url" controls preload="metadata"></video><label class="media-file-button"><input type="file" accept="video/mp4,video/webm,video/quicktime" :disabled="busy" @change="uploadMedia($event, 'video')"><span>{{ busy ? 'Wird hochgeladen…' : 'Video hochladen' }}</span></label></template></article>
            </div>
        </section>

        <section v-if="group === 'notifications'" class="settings-panel admin-notification-settings">
            <div class="settings-panel-head"><p class="eyebrow">SUPER ADMIN</p><h2>Benachrichtigungen über neue Kundenanfragen</h2><p>Diese Empfänger gehören zur Plattformverwaltung. Die Einstellungen der einzelnen Kunden und deren Tarife ändern daran nichts.</p></div>
            <div class="settings-form-grid"><label>E-Mail des Empfängers<input v-model.trim="form.admin_notification_email" type="email" placeholder="admin@lookdo.app"><small>Wird verwendet, sobald E-Mail unten aktiviert ist.</small></label><label>Mobilnummer des Empfängers<input v-model.trim="form.admin_notification_phone" type="tel" placeholder="+49 …"><small>Bitte möglichst im internationalen Format eintragen.</small></label><label>Maximale Admin-SMS pro Monat<input v-model.number="form.admin_notification_sms_monthly_limit" type="number" min="1" max="10000"><small>Eigenes Sicherheitslimit für Plattformmeldungen.</small></label></div>
            <div class="sms-event-settings"><h3>Kanäle</h3><div class="settings-choice-grid">
                <label class="settings-toggle"><input v-model="form.admin_notifications.push" type="checkbox" :disabled="!data.notifications.push_configured"><span><b>Push</b><small>{{ data.notifications.push_configured ? 'Auf allen für Super Admin registrierten Browsern.' : 'VAPID ist auf dem Server noch nicht konfiguriert.' }}</small></span></label>
                <label class="settings-toggle"><input v-model="form.admin_notifications.email" type="checkbox"><span><b>E-Mail</b><small>An die oben eingetragene Adresse.</small></span></label>
                <label class="settings-toggle"><input v-model="form.admin_notifications.sms" type="checkbox" :disabled="!form.integrations.sms"><span><b>SMS</b><small>{{ form.integrations.sms ? 'Unabhängig vom Tarif des Kunden.' : 'Zuerst das globale SMS-Gateway aktivieren.' }}</small></span></label>
            </div></div>
            <div class="settings-panel-actions admin-push-actions"><span><b>{{ adminPushDevices }}</b> Push-Gerät(e) für Ihr Super-Admin-Konto registriert.<small v-if="pushStatus">{{ pushStatus }}</small></span><div><button type="button" class="button ghost" :disabled="pushBusy || !data.notifications.push_configured" @click="syncAdminPush(false)">Auf diesem Browser ausschalten</button><button type="button" class="button" :disabled="pushBusy || !data.notifications.push_configured" @click="syncAdminPush(true)">{{ pushBusy ? 'Wird eingerichtet…' : 'Auf diesem Browser einschalten' }}</button></div></div>
        </section>

        <section v-if="group === 'sms'" class="settings-panel sms-settings">
            <div class="settings-panel-head"><p class="eyebrow">SMS-GATEWAY</p><h2>SMS an Endkunden</h2><p>SMS werden nur bei aktivierten wichtigen Ereignissen und innerhalb des jeweiligen Tariflimits versendet.</p></div>
            <div class="sms-master-switch" :class="form.integrations.sms ? 'active' : 'inactive'">
                <label class="settings-toggle"><input v-model="form.integrations.sms" type="checkbox"><span><b>SMS-Versand aktivieren</b><small>Globale Freigabe für den Versand über seven.io. Tarifberechtigungen und Monatslimits gelten zusätzlich.</small></span></label>
                <strong>{{ form.integrations.sms ? (persistedSmsEnabled ? 'Aktiv' : 'Aktivierung noch speichern') : 'Deaktiviert' }}</strong>
            </div>
            <div class="settings-form-grid"><label>Anbieter<select v-model="form.sms_provider"><option v-for="provider in data.sms.providers" :key="provider.value" :value="provider.value">{{ provider.label }}</option></select></label><label>Absender<input v-model="form.sms_sender" maxlength="16" placeholder="LOOKDO"><small>Maximal 11 alphanumerische oder 16 numerische Zeichen.</small></label><label>seven.io API-Key<input v-model="form.sms_seven_api_key" type="password" autocomplete="new-password" :placeholder="data.sms.api_key_configured ? 'Gespeichert – leer lassen, um beizubehalten' : 'API-Key eintragen'"><small>{{ data.sms.api_key_configured ? 'API-Key ist verschlüsselt gespeichert.' : 'Noch kein API-Key gespeichert.' }}</small></label><label>seven.io Signing Key<input v-model="form.sms_seven_signing_key" type="password" autocomplete="new-password" :placeholder="data.sms.signing_key_configured ? 'Gespeichert – leer lassen, um beizubehalten' : 'Signing Key eintragen'"><small>{{ data.sms.signing_key_configured ? 'Signing Key ist verschlüsselt gespeichert.' : 'Für sichere Delivery-Reports erforderlich.' }}</small></label><label class="wide">Delivery-Webhook<input :value="data.sms.webhook_url" readonly></label><label class="check"><input v-model="form.sms_clear_api_key" type="checkbox"> Gespeicherten API-Key löschen</label><label class="check"><input v-model="form.sms_clear_signing_key" type="checkbox"> Gespeicherten Signing Key löschen</label></div>
            <div class="sms-event-settings"><h3>Wichtige Ereignisse</h3><div class="settings-choice-grid"><label class="settings-toggle"><input v-model="form.sms_events.request_received" type="checkbox"><span><b>Anfrage erhalten</b><small>Bestätigung für den Endkunden.</small></span></label><label class="settings-toggle"><input v-model="form.sms_events.master_replied" type="checkbox"><span><b>Meister hat geantwortet</b><small>Hinweis mit Link zur Antwort.</small></span></label><label class="settings-toggle"><input v-model="form.sms_events.work_ready" type="checkbox"><span><b>Arbeit fertig</b><small>Benachrichtigung über die Fertigstellung.</small></span></label><label class="settings-toggle"><input v-model="form.sms_events.agreement_reminder" type="checkbox"><span><b>Vereinbarung erinnern</b><small>Erinnerung an Termin oder Absprache.</small></span></label></div></div>
            <div class="settings-panel-actions"><span>{{ !form.integrations.sms ? 'SMS-Versand ist global deaktiviert.' : !persistedSmsEnabled ? 'Aktivierung zuerst speichern.' : 'Neue Schlüssel zuerst speichern, danach kann die Verbindung geprüft werden.' }}</span><button type="button" class="button ghost" :disabled="busy || !persistedSmsEnabled || !data.sms.api_key_configured" @click="testSms">seven.io-Verbindung prüfen</button></div>
        </section>

        <section v-if="group === 'openai'" class="settings-panel openai-settings">
            <div class="settings-panel-head"><p class="eyebrow">OPENAI USAGE & COSTS</p><h2>Offizielle Kosten abgleichen</h2><p>LOOKDO vergleicht den eigenen Verbrauch pro Kunde mit den abrechnungsrelevanten Organisationskosten von OpenAI.</p></div>
            <div class="openai-connection-note"><strong>Zwei getrennte Schlüssel</strong><p>Der normale OPENAI_API_KEY führt Anfragen aus. Für organisationsweite Usage- und Costs-Daten verlangt OpenAI zusätzlich einen Admin Key des Organization Owners.</p></div>
            <div class="settings-form-grid">
                <label>OpenAI Project ID<input v-model.trim="form.openai_project_id" placeholder="proj_…"><small>Optional. Leer lassen, um alle Projekte der Organisation zu summieren.</small></label>
                <label>OpenAI Admin Key<input v-model="form.openai_admin_key" type="password" autocomplete="new-password" :placeholder="data.openai.admin_key_configured ? 'Gespeichert – leer lassen, um beizubehalten' : 'sk-admin-…'"><small>{{ data.openai.admin_key_configured ? 'Admin Key ist verschlüsselt gespeichert.' : 'Nur ein Organization Owner kann diesen Schlüssel erstellen.' }}</small></label>
                <label class="check"><input v-model="form.openai_clear_admin_key" type="checkbox"> Gespeicherten Admin Key löschen</label>
            </div>
            <div class="settings-panel-actions"><span><a :href="data.openai.admin_keys_url" target="_blank" rel="noopener">Admin Key bei OpenAI erstellen ↗</a> · <a :href="data.openai.usage_dashboard_url" target="_blank" rel="noopener">OpenAI Usage öffnen ↗</a></span><button type="button" class="button ghost" :disabled="busy || !persistedOpenAiAdminKey" @click="testOpenAi">Verbindung und Kosten prüfen</button></div>
        </section>

        <section v-if="group === 'operation'" class="settings-panel">
            <div class="settings-panel-head"><p class="eyebrow">BETRIEB</p><h2>Verfügbarkeit und Funktionen</h2><p>Globale Freigaben für die gesamte Plattform.</p></div>
            <div class="settings-choice-grid"><fieldset><legend>Plattformstatus</legend><label class="settings-toggle"><input v-model="form.registration_enabled" type="checkbox"><span><b>Registrierung geöffnet</b><small>Neue Kunden können ein Konto anlegen.</small></span></label><label class="settings-toggle warning"><input v-model="form.maintenance" type="checkbox"><span><b>Wartungsmodus</b><small>Öffentliche Seiten und Kundenbereiche werden gesperrt. Super Admin und Webhooks bleiben erreichbar.</small></span></label></fieldset><fieldset><legend>Integrationen</legend><label class="settings-toggle"><input v-model="form.integrations.stripe" type="checkbox"><span><b>Stripe</b><small>Online-Zahlungen und Abonnements.</small></span></label><label class="settings-toggle"><input v-model="form.integrations.openai" type="checkbox"><span><b>OpenAI</b><small>Klassifizierung, Texte und Übersetzungen.</small></span></label><label class="settings-toggle"><input v-model="form.integrations.sms" type="checkbox"><span><b>SMS</b><small>Wichtige Nachrichten an Endkunden.</small></span></label></fieldset><fieldset><legend>Verfügbare Sprachen</legend><label v-for="locale in locales" :key="locale[0]" class="settings-toggle"><input v-model="form.enabled_locales" type="checkbox" :value="locale[0]"><span><b>{{ locale[1] }}</b></span></label></fieldset></div>
        </section>

        <div class="settings-save-bar"><span>Änderungen gelten nach dem Speichern für die gesamte Plattform.</span><button class="button" :disabled="busy">{{ busy ? 'Wird gespeichert…' : 'Einstellungen speichern' }}</button></div>
    </form>
</section>
</template>

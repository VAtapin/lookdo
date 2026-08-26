<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
import { locale, tr } from '../i18n';

const route = useRoute();
const router = useRouter();
const step = ref(1);
const busy = ref(false);
const classifying = ref(false);
const error = ref('');
const platform = ref<any>({ plans: [] });
const classification = ref<any>(null);
const slugEdited = ref(false);
const currencyEdited = ref(false);
const availability = reactive<any>({ checking: false, email: null, slug: null });
let availabilityTimer: number | undefined;
let classifyTimer: number | undefined;
let classificationRequest = 0;

const form = reactive({
    name: '', email: '', password: '', password_confirmation: '', business_name: '', slug: '', country: 'DE', locale: locale.value,
    business_description: '', classification_id: null as number | null, variation_id: null as number | null,
    plan_id: Number(route.query.plan) || null as number | null, billing_cycle: 'monthly', currency: 'EUR', confirm_business_customer: false, accept_terms: false, accept_privacy: false,
});

const candidates = computed<any[]>(() => classification.value?.candidates || []);
const chosen = computed(() => candidates.value.find((candidate: any) => candidate.variation_id === form.variation_id) || platform.value.default_template || null);
const preview = computed(() => chosen.value?.preview || platform.value.default_template?.preview || { image: '/brand/service-renovation.webp', primary_color: '#ff6b00', secondary_color: '#25282e' });
const previewStyle = computed(() => ({ '--preview-primary': preview.value.primary_color, '--preview-secondary': preview.value.secondary_color }));
const words = computed(() => form.business_description.trim().split(/\s+/u).filter(Boolean).length);

onMounted(async () => {
    platform.value = await api('/platform');
    if (!form.plan_id) form.plan_id = platform.value.plans[0]?.id;
    applyDefaultCurrency();
});

onBeforeUnmount(() => {
    window.clearTimeout(availabilityTimer);
    window.clearTimeout(classifyTimer);
});

watch(() => form.locale, () => { if (!currencyEdited.value) applyDefaultCurrency(); });
watch(() => [form.email, form.slug, form.business_name], () => {
    availability.email = null;
    availability.slug = null;
    window.clearTimeout(availabilityTimer);
    availabilityTimer = window.setTimeout(() => checkAvailability(false), 650);
});
watch(() => [form.business_description, form.locale], () => {
    window.clearTimeout(classifyTimer);
    if (words.value < 4) return;
    classifyTimer = window.setTimeout(() => classify(true), 750);
});

function applyDefaultCurrency() {
    form.currency = form.locale === 'ru' ? 'RUB' : form.locale === 'uk' ? 'UAH' : 'EUR';
}

function cleanSlug() {
    form.slug = form.slug.toLowerCase().trim().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').replace(/-+/g, '-').slice(0, 63);
}

async function checkAvailability(showError = true) {
    if (!form.email && !form.business_name && !form.slug) return false;
    availability.checking = true;
    try {
        const result = await api<any>('/register/availability', { method: 'POST', body: JSON.stringify({ email: form.email, slug: form.slug, business_name: form.business_name }) });
        availability.email = result.email;
        availability.slug = form.slug || form.business_name ? result.slug : null;
        if (!slugEdited.value && !form.slug && form.business_name && result.slug.normalized) form.slug = result.slug.normalized;
        if (showError && (!result.email.available || availability.slug?.available === false)) error.value = !result.email.available ? result.email.message : result.slug.message;
        return result.email.available && (availability.slug?.available ?? true);
    } catch (exception: any) {
        if (showError) error.value = exception.message;
        return false;
    } finally {
        availability.checking = false;
    }
}

function useSuggestedSlug() {
    if (!availability.slug?.suggested) return;
    form.slug = availability.slug.suggested;
    slugEdited.value = true;
    checkAvailability(false);
}

async function classify(automatic = false) {
    if (form.business_description.trim().length < 3) {
        if (!automatic) error.value = tr('describeActivityFirst');
        return;
    }
    const request = ++classificationRequest;
    classifying.value = true;
    if (!automatic) error.value = '';
    try {
        const result = await api<any>('/classify', { method: 'POST', body: JSON.stringify({ description: form.business_description, locale: form.locale }) });
        if (request !== classificationRequest) return;
        classification.value = result;
        form.classification_id = result.id;
    } catch {
        if (request !== classificationRequest) return;
        classification.value = { id: null, source: 'fallback', candidates: [platform.value.default_template].filter(Boolean) };
        form.classification_id = null;
    } finally {
        if (request !== classificationRequest) return;
        const fallback = platform.value.default_template;
        const available = classification.value?.candidates?.length ? classification.value.candidates : [fallback].filter(Boolean);
        classification.value = { ...(classification.value || {}), source: classification.value?.source || 'fallback', candidates: available };
        if (!available.some((candidate: any) => candidate.variation_id === form.variation_id)) form.variation_id = available[0]?.variation_id || fallback?.variation_id || null;
        classifying.value = false;
    }
}

async function nextAccount() {
    if (!form.name || !form.email || form.password.length < 10 || form.password !== form.password_confirmation || !form.business_name) {
        error.value = tr('completeFields');
        return;
    }
    error.value = '';
    if (!await checkAvailability(true)) return;
    step.value = 2;
}

function confirmActivity() {
    if (!form.variation_id) return;
    error.value = '';
    step.value = 3;
}

function price(plan: any): number | null {
    const configured = plan.prices?.[form.currency]?.[form.billing_cycle];
    if (configured !== null && configured !== undefined) return Number(configured);
    return form.currency === plan.currency ? Number(form.billing_cycle === 'yearly' ? plan.price_yearly : plan.price_monthly) : null;
}

function formatPrice(value: number | null): string {
    if (value === null) return '—';
    const numberLocale = form.locale === 'uk' ? 'uk-UA' : form.locale === 'ru' ? 'ru-RU' : form.locale === 'de' ? 'de-DE' : 'en-GB';
    return new Intl.NumberFormat(numberLocale, { style: 'currency', currency: form.currency, maximumFractionDigits: value % 1 ? 2 : 0 }).format(value);
}

function yearlySaving(plan: any): number {
    const monthly = Number(plan.prices?.[form.currency]?.monthly || 0);
    const yearly = Number(plan.prices?.[form.currency]?.yearly || 0);
    return monthly > 0 && yearly > 0 ? Math.max(0, Math.round((1 - yearly / (monthly * 12)) * 100)) : 0;
}

async function register() {
    busy.value = true;
    error.value = '';
    try {
        const result = await api<any>('/register', { method: 'POST', body: JSON.stringify(form) });
        if (result.checkout_url) location.href = result.checkout_url;
        else if (result.payment_required) router.push('/app/billing?payment=required');
        else router.push('/app');
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
  <div class="register-page">
    <aside class="register-aside">
      <RouterLink class="public-wordmark" :to="`/${locale}`"><img :src="'/brand/lookdo-logo.png'" alt="LOOKDO"></RouterLink>
      <div class="register-promise"><p class="eyebrow">{{ tr('create') }}</p><h1>{{ tr('registerTitle') }}</h1><p>{{ tr('registerIntro') }}</p></div>
      <div class="register-aside-lower">
        <div class="register-progress">
          <div v-for="(item, index) in [tr('account'), tr('activity'), tr('template'), tr('registerPlan')]" :key="item" :class="{ active: index + 1 <= step }"><span>{{ index + 1 }}</span><p>{{ item }}</p></div>
        </div>
        <div class="template-live-phone" :style="previewStyle">
          <div class="template-live-island"></div>
          <div class="template-live-head"><b>{{ form.business_name || 'LOOKDO' }}</b><small>{{ chosen?.variation || tr('templatePreview') }}</small></div>
          <img :src="preview.image" :alt="chosen?.variation || tr('templatePreview')">
          <div class="template-live-copy"><i></i><i></i><i></i></div>
          <button type="button">{{ chosen?.variation || tr('templateReady') }}</button>
        </div>
      </div>
    </aside>
    <main class="register-main"><div class="register-card"><div class="mobile-step">{{ step }} / 4</div>
      <form v-if="step === 1" @submit.prevent="nextAccount">
        <p class="eyebrow">{{ tr('stepLabel') }} 01</p><h2>{{ tr('accountBusiness') }}</h2>
        <div class="form-grid">
          <label>{{ tr('name') }}<input v-model="form.name" required autocomplete="name"></label>
          <label class="availability-field">{{ tr('email') }}<input v-model="form.email" required type="email" autocomplete="email" @blur="checkAvailability(false)"><small class="field-status" :class="form.email && availability.email ? (availability.email.available ? 'field-ok' : 'field-error') : ''" aria-live="polite">{{ form.email && availability.email ? availability.email.message : ' ' }}</small></label>
          <label>{{ tr('password') }}<input v-model="form.password" required type="password" autocomplete="new-password"></label><label>{{ tr('repeatPassword') }}<input v-model="form.password_confirmation" required type="password" autocomplete="new-password"></label>
          <label class="wide">{{ tr('businessName') }}<input v-model="form.business_name" required></label><label>{{ tr('country') }}<select v-model="form.country"><option value="DE">Deutschland</option><option value="AT">Österreich</option><option value="CH">Schweiz</option><option value="UA">Україна</option><option value="RU">Россия</option><option value="GB">United Kingdom</option></select></label>
          <label>{{ tr('language') }}<select v-model="form.locale"><option value="de">Deutsch</option><option value="en">English</option><option value="ru">Русский</option><option value="uk">Українська</option></select></label>
          <label class="wide">{{ tr('appAddress') }}<div class="slug-check-row"><div class="slug-input"><input v-model="form.slug" maxlength="63" placeholder="mein-betrieb" @input="slugEdited = true; cleanSlug()"><span>.lookdo.app</span></div><button type="button" class="button small ghost" :disabled="availability.checking" @click="checkAvailability(false)">{{ availability.checking ? '…' : tr('check') }}</button></div><small v-if="availability.slug" :class="availability.slug.available ? 'field-ok' : 'field-error'">{{ availability.slug.message }} <button v-if="!availability.slug.available && availability.slug.suggested" type="button" class="inline-action" @click="useSuggestedSlug">{{ availability.slug.suggested }} →</button></small><small v-else>{{ tr('appAddressHelp') }}</small></label>
        </div><p v-if="error" class="alert error">{{ error }}</p><button class="button full" :disabled="availability.checking">{{ tr('continue') }} →</button>
      </form>

      <form v-if="step === 2" @submit.prevent="classify(false)">
        <p class="eyebrow">{{ tr('stepLabel') }} 02</p><h2>{{ tr('whatBusiness') }}</h2><p>{{ tr('describeWork') }}</p>
        <label>{{ tr('yourActivity') }}<textarea v-model="form.business_description" rows="5" required :placeholder="tr('activityPlaceholder')"></textarea></label>
        <div class="activity-analysis-state" :class="{ working: classifying }"><span></span>{{ classifying ? tr('analysing') : (words < 4 ? tr('activityAutoHint') : classification ? tr('templateFound') : tr('activityWaiting')) }}</div>
        <div v-if="candidates.length" class="candidate-list inline-candidates"><label v-for="candidate in candidates" :key="candidate.variation_id" :class="{ selected: form.variation_id === candidate.variation_id }"><input v-model="form.variation_id" type="radio" :value="candidate.variation_id"><img :src="candidate.preview?.image || '/brand/service-renovation.webp'" alt=""><span><b>{{ candidate.variation }}</b><small>{{ candidate.category }}<template v-if="!candidate.fallback"> · {{ Math.round(candidate.score * 100) }}%</template></small></span></label></div>
        <div v-if="classification?.source === 'fallback'" class="alert fallback">{{ tr('noTemplate') }}</div>
        <p v-if="error" class="alert error">{{ error }}</p>
        <div class="form-actions"><button type="button" class="button ghost" @click="step = 1">← {{ tr('back') }}</button><button type="submit" class="button ghost" :disabled="classifying">{{ tr('checkAgain') }}</button><button v-if="form.variation_id" type="button" class="button" @click="confirmActivity">{{ tr('showTemplate') }} →</button></div>
      </form>

      <form v-if="step === 3" @submit.prevent="step = 4">
        <p class="eyebrow">{{ tr('stepLabel') }} 03</p><h2>{{ tr('yourTemplateReady') }}</h2><p>{{ tr('templateChangesLive') }}</p>
        <div class="template-confirmation" :style="previewStyle"><img :src="preview.image" :alt="chosen?.variation"><div><small>{{ chosen?.category }}</small><h3>{{ chosen?.variation }}</h3><p>{{ tr('templateConfirmText') }}</p><code>{{ chosen?.template_code }}</code></div></div>
        <div class="form-actions"><button type="button" class="button ghost" @click="step = 2">← {{ tr('changeActivity') }}</button><button class="button">{{ tr('confirm') }} →</button></div>
      </form>

      <form v-if="step === 4" @submit.prevent="register">
        <p class="eyebrow">{{ tr('stepLabel') }} 04</p><h2>{{ tr('choosePlan') }}</h2>
        <div class="billing-controls"><label>{{ tr('currency') }}<select v-model="form.currency" @change="currencyEdited = true"><option value="EUR">EUR — €</option><option value="RUB">RUB — ₽</option><option value="UAH">UAH — ₴</option></select></label><div class="cycle"><button type="button" :class="{ active: form.billing_cycle === 'monthly' }" @click="form.billing_cycle = 'monthly'">{{ tr('monthly') }}</button><button type="button" :class="{ active: form.billing_cycle === 'yearly' }" @click="form.billing_cycle = 'yearly'">{{ tr('yearly') }}</button></div></div>
        <div class="plan-options"><label v-for="plan in platform.plans" :key="plan.id" :class="{ selected: form.plan_id === plan.id }"><input v-model="form.plan_id" type="radio" :value="plan.id"><span><b>{{ plan.name }}</b><small>{{ plan.description }}</small><em v-if="form.billing_cycle === 'yearly' && yearlySaving(plan)">{{ tr('save') }} {{ yearlySaving(plan) }}%</em></span><strong>{{ formatPrice(price(plan)) }}<small>{{ form.billing_cycle === 'yearly' ? tr('perYear') : tr('month') }}</small></strong></label></div>
        <label class="check"><input v-model="form.confirm_business_customer" type="checkbox" required> {{ tr('confirmBusinessCustomer') }}</label><label class="check"><input v-model="form.accept_terms" type="checkbox" required> {{ tr('acceptTerms') }}</label><label class="check"><input v-model="form.accept_privacy" type="checkbox" required> {{ tr('acceptPrivacy') }}</label>
        <p v-if="error" class="alert error">{{ error }}</p><div class="form-actions"><button type="button" class="button ghost" @click="step = 3">← {{ tr('back') }}</button><button class="button" :disabled="busy">{{ busy ? tr('creating') : tr('create') }}</button></div>
      </form>
    </div></main>
  </div>
</template>

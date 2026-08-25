<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
import { locale, tr } from '../i18n';

const route = useRoute();
const router = useRouter();
const step = ref(1);
const busy = ref(false);
const error = ref('');
const platform = ref<any>({ plans: [] });
const classification = ref<any>(null);
const form = reactive({
    name: '', email: '', password: '', password_confirmation: '', business_name: '', slug: '', country: 'DE', locale: locale.value,
    business_description: '', classification_id: null as number | null, variation_id: null as number | null,
    plan_id: Number(route.query.plan) || null as number | null, billing_cycle: 'monthly', accept_terms: false, accept_privacy: false,
});

onMounted(async () => {
    platform.value = await api('/platform');
    if (!form.plan_id) form.plan_id = platform.value.plans[0]?.id;
});

const chosen = computed(() => classification.value?.candidates?.find((candidate: any) => candidate.variation_id === form.variation_id));

async function classify() {
    busy.value = true;
    error.value = '';
    try {
        classification.value = await api('/classify', { method: 'POST', body: JSON.stringify({ description: form.business_description, locale: form.locale }) });
        form.classification_id = classification.value.id;
    } catch {
        classification.value = { id: null, source: 'fallback', candidates: [platform.value.default_template] };
        form.classification_id = null;
    } finally {
        const fallback = platform.value.default_template;
        const candidates = classification.value?.candidates?.length ? classification.value.candidates : [fallback].filter(Boolean);
        classification.value = { ...(classification.value || {}), source: classification.value?.source || 'fallback', candidates };
        form.variation_id = candidates[0]?.variation_id || fallback?.variation_id || null;
        step.value = 3;
        busy.value = false;
    }
}

async function register() {
    busy.value = true;
    error.value = '';
    try {
        const result = await api('/register', { method: 'POST', body: JSON.stringify(form) });
        if (result.checkout_url) location.href = result.checkout_url;
        else router.push('/app');
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}

function nextAccount() {
    if (!form.name || !form.email || form.password.length < 10 || form.password !== form.password_confirmation || !form.business_name) {
        error.value = tr('completeFields');
        return;
    }
    error.value = '';
    step.value = 2;
}
</script>

<template>
  <div class="register-page">
    <aside class="register-aside">
      <RouterLink class="public-wordmark" :to="`/${locale}`"><b>LOOK</b><span>DO</span></RouterLink>
      <div><p class="eyebrow">{{ tr('create') }}</p><h1>{{ tr('registerTitle') }}</h1><p>{{ tr('registerIntro') }}</p></div>
      <div class="register-progress">
        <div v-for="(item, index) in [tr('account'), tr('activity'), tr('template'), tr('registerPlan')]" :key="item" :class="{ active: index + 1 <= step }"><span>{{ index + 1 }}</span><p>{{ item }}</p></div>
      </div>
    </aside>
    <main class="register-main"><div class="register-card"><div class="mobile-step">{{ step }} / 4</div>
      <form v-if="step === 1" @submit.prevent="nextAccount">
        <p class="eyebrow">{{ tr('stepLabel') }} 01</p><h2>{{ tr('accountBusiness') }}</h2>
        <div class="form-grid">
          <label>{{ tr('name') }}<input v-model="form.name" required autocomplete="name"></label><label>{{ tr('email') }}<input v-model="form.email" required type="email" autocomplete="email"></label>
          <label>{{ tr('password') }}<input v-model="form.password" required type="password" autocomplete="new-password"></label><label>{{ tr('repeatPassword') }}<input v-model="form.password_confirmation" required type="password" autocomplete="new-password"></label>
          <label class="wide">{{ tr('businessName') }}<input v-model="form.business_name" required></label><label>{{ tr('country') }}<select v-model="form.country"><option value="DE">Deutschland</option><option value="AT">Österreich</option><option value="CH">Schweiz</option><option value="RU">Россия</option><option value="GB">United Kingdom</option></select></label>
          <label>{{ tr('language') }}<select v-model="form.locale"><option value="de">Deutsch</option><option value="en">English</option><option value="ru">Русский</option></select></label>
        </div><p v-if="error" class="alert error">{{ error }}</p><button class="button full">{{ tr('continue') }} →</button>
      </form>
      <form v-if="step === 2" @submit.prevent="classify">
        <p class="eyebrow">{{ tr('stepLabel') }} 02</p><h2>{{ tr('whatBusiness') }}</h2><p>{{ tr('describeWork') }}</p>
        <label>{{ tr('yourActivity') }}<textarea v-model="form.business_description" rows="6" required :placeholder="tr('activityPlaceholder')"></textarea></label>
        <p class="privacy-note">{{ tr('existingOnly') }}</p><p v-if="error" class="alert error">{{ error }}</p>
        <div class="form-actions"><button type="button" class="button ghost" @click="step = 1">← {{ tr('back') }}</button><button class="button" :disabled="busy">{{ busy ? tr('analysing') : tr('findTemplate') + ' →' }}</button></div>
      </form>
      <form v-if="step === 3" @submit.prevent="step = 4">
        <p class="eyebrow">{{ tr('stepLabel') }} 03</p><h2>{{ tr('whichTemplate') }}</h2><p>{{ tr('confirmBest') }}</p>
        <div class="candidate-list"><label v-for="candidate in classification?.candidates" :key="candidate.variation_id" :class="{ selected: form.variation_id === candidate.variation_id }"><input v-model="form.variation_id" type="radio" :value="candidate.variation_id"><span><b>{{ candidate.variation }}</b><small>{{ candidate.category }}<template v-if="!candidate.fallback"> · {{ Math.round(candidate.score * 100) }}%</template></small></span></label></div>
        <div v-if="classification?.source === 'fallback'" class="alert fallback">{{ tr('noTemplate') }}</div><p v-if="chosen" class="privacy-note">{{ tr('template') }}: {{ chosen.template_code }}</p>
        <div class="form-actions"><button type="button" class="button ghost" @click="step = 2">← {{ tr('back') }}</button><button class="button">{{ tr('confirm') }} →</button></div>
      </form>
      <form v-if="step === 4" @submit.prevent="register">
        <p class="eyebrow">{{ tr('stepLabel') }} 04</p><h2>{{ tr('choosePlan') }}</h2>
        <div class="plan-options"><label v-for="plan in platform.plans" :key="plan.id" :class="{ selected: form.plan_id === plan.id }"><input v-model="form.plan_id" type="radio" :value="plan.id"><span><b>{{ plan.name }}</b><small>{{ plan.description }}</small></span><strong>{{ plan.price_monthly }} €<small>{{ tr('month') }}</small></strong></label></div>
        <div class="cycle"><button type="button" :class="{ active: form.billing_cycle === 'monthly' }" @click="form.billing_cycle = 'monthly'">{{ tr('monthly') }}</button><button type="button" :class="{ active: form.billing_cycle === 'yearly' }" @click="form.billing_cycle = 'yearly'">{{ tr('yearly') }}</button></div>
        <label class="check"><input v-model="form.accept_terms" type="checkbox" required> {{ tr('acceptTerms') }}</label><label class="check"><input v-model="form.accept_privacy" type="checkbox" required> {{ tr('acceptPrivacy') }}</label>
        <p v-if="error" class="alert error">{{ error }}</p><div class="form-actions"><button type="button" class="button ghost" @click="step = 3">← {{ tr('back') }}</button><button class="button" :disabled="busy">{{ busy ? tr('creating') : tr('create') }}</button></div>
      </form>
    </div></main>
  </div>
</template>

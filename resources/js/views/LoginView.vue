<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { locale, tr } from '../i18n';

const router = useRouter();
const mode = ref<'login' | 'forgot'>('login');
const email = ref('');
const password = ref('');
const remember = ref(true);
const error = ref('');
const message = ref('');
const busy = ref(false);
async function submit() {
    busy.value = true; error.value = '';
    try {
        if (mode.value === 'forgot') message.value = (await api('/forgot-password', { method: 'POST', body: JSON.stringify({ email: email.value }) })).message;
        else { const result = await api('/login', { method: 'POST', body: JSON.stringify({ email: email.value, password: password.value, remember: remember.value }) }); router.push(result.user.is_super_admin ? '/control' : '/app'); }
    } catch (exception: any) { error.value = exception.message; }
    finally { busy.value = false; }
}
</script>

<template><div class="auth-page"><section class="auth-aside"><RouterLink class="public-wordmark" :to="`/${locale}`"><img :src="'/brand/lookdo-logo.png'" alt="LOOKDO"></RouterLink><div><p class="eyebrow">LOOK. DO.</p><h1>{{ tr('welcomeBack') }}</h1><p>{{ tr('loginAside') }}</p></div></section><section class="auth-panel"><form class="auth-card" @submit.prevent="submit"><p class="eyebrow">{{ mode === 'login' ? tr('login') : tr('passwordReset') }}</p><h2>{{ mode === 'login' ? tr('accountTitle') : tr('restoreAccess') }}</h2><label>{{ tr('email') }}<input v-model="email" type="email" autocomplete="email" required></label><label v-if="mode === 'login'">{{ tr('password') }}<input v-model="password" type="password" autocomplete="current-password" required></label><label v-if="mode === 'login'" class="check"><input v-model="remember" type="checkbox"> {{ tr('remember') }}</label><p v-if="error" class="alert error">{{ error }}</p><p v-if="message" class="alert success">{{ message }}</p><button class="button full" :disabled="busy">{{ busy ? '…' : mode === 'login' ? tr('login') : tr('sendReset') }}</button><button type="button" class="link-button" @click="mode = mode === 'login' ? 'forgot' : 'login'">{{ mode === 'login' ? tr('forgot') : tr('backLogin') }}</button><p>{{ tr('newLookdo') }} <RouterLink :to="`/${locale}/register`">{{ tr('create') }}</RouterLink></p></form></section></div></template>

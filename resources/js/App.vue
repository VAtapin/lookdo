<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { locale, setLocale, tr, type Locale } from './i18n';

const route = useRoute();
const router = useRouter();
const isTenantHost = document.documentElement.dataset.tenantHost === 'true';
const publicPage = computed(() => !route.path.startsWith('/app') && !route.path.startsWith('/control'));
const showChrome = computed(() => !isTenantHost && publicPage.value && !/(^|\/)(login|register)(\/|$)/.test(route.path));
onMounted(() => { const value = route.params.locale as Locale; if (['de', 'en', 'ru', 'uk'].includes(value)) setLocale(value); });
watch(() => route.params.locale, value => { if (['de', 'en', 'ru', 'uk'].includes(String(value))) setLocale(value as Locale); });
function switchLocale(value: Locale) {
    setLocale(value);
    if (publicPage.value) {
        const parts = route.path.split('/').filter(Boolean);
        if (['de', 'en', 'ru', 'uk'].includes(parts[0])) parts.shift();
        router.push('/' + value + (parts.length ? '/' + parts.join('/') : '/'));
    }
}
</script>

<template>
  <div class="site-shell">
    <header v-if="showChrome" class="topbar">
      <RouterLink class="public-wordmark" :to="`/${locale}`"><img :src="'/brand/lookdo-logo.png'" alt="LOOKDO"></RouterLink>
      <nav class="desktop-nav"><RouterLink :to="{ path: `/${locale}`, hash: '#features' }">{{ tr('features') }}</RouterLink><RouterLink :to="{ path: `/${locale}`, hash: '#how' }">{{ tr('how') }}</RouterLink><RouterLink :to="{ path: `/${locale}`, hash: '#audience' }">{{ tr('forWhom') }}</RouterLink><RouterLink :to="{ path: `/${locale}`, hash: '#pricing' }">{{ tr('pricing') }}</RouterLink></nav>
      <div class="top-actions"><select :value="locale" aria-label="Language" @change="switchLocale(($event.target as HTMLSelectElement).value as Locale)"><option value="de">DE</option><option value="en">EN</option><option value="ru">RU</option><option value="uk">UK</option></select><RouterLink class="text-link" :to="`/${locale}/login`">{{ tr('login') }}</RouterLink><RouterLink class="button small" :to="`/${locale}/register`">{{ tr('create') }}</RouterLink></div>
    </header>
    <main><RouterView /></main>
    <footer v-if="showChrome" class="footer"><div><RouterLink class="public-wordmark" :to="`/${locale}`"><img :src="'/brand/lookdo-logo.png'" alt="LOOKDO"></RouterLink><p>© 2026 LOOKDO. {{ tr('footer') }}</p></div><div class="footer-links"><RouterLink :to="`/${locale}/impressum`">{{ tr('impressum') }}</RouterLink><RouterLink :to="`/${locale}/datenschutz`">{{ tr('privacy') }}</RouterLink><RouterLink :to="`/${locale}/agb`">{{ tr('terms') }}</RouterLink><RouterLink :to="`/${locale}/kontakt`">{{ tr('contact') }}</RouterLink></div></footer>
  </div>
</template>

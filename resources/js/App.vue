<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { locale, setLocale, tr, type Locale } from './i18n';

const route = useRoute();
const router = useRouter();
const publicPage = computed(() => !route.path.startsWith('/app') && !route.path.startsWith('/control'));
const showChrome = computed(() => publicPage.value && !/(^|\/)(login|register)(\/|$)/.test(route.path));
onMounted(() => { const value = route.params.locale as Locale; if (['de', 'en', 'ru'].includes(value)) setLocale(value); });
watch(() => route.params.locale, value => { if (['de', 'en', 'ru'].includes(String(value))) setLocale(value as Locale); });
function switchLocale(value: Locale) {
    setLocale(value);
    if (publicPage.value) {
        const parts = route.path.split('/').filter(Boolean);
        if (['de', 'en', 'ru'].includes(parts[0])) parts.shift();
        router.push('/' + value + (parts.length ? '/' + parts.join('/') : '/'));
    }
}
</script>

<template>
  <div class="site-shell">
    <header v-if="showChrome" class="topbar">
      <RouterLink class="public-wordmark" :to="`/${locale}`"><b>LOOK</b><span>DO</span></RouterLink>
      <nav class="desktop-nav"><a href="#features">{{ tr('features') }}</a><a href="#how">{{ tr('how') }}</a><a href="#audience">{{ tr('forWhom') }}</a><a href="#pricing">{{ tr('pricing') }}</a></nav>
      <div class="top-actions"><select :value="locale" aria-label="Language" @change="switchLocale(($event.target as HTMLSelectElement).value as Locale)"><option value="de">DE</option><option value="en">EN</option><option value="ru">RU</option></select><RouterLink class="text-link" :to="`/${locale}/login`">{{ tr('login') }}</RouterLink><RouterLink class="button small" :to="`/${locale}/register`">{{ tr('create') }}</RouterLink></div>
    </header>
    <main><RouterView /></main>
    <footer v-if="showChrome" class="footer"><div><RouterLink class="public-wordmark" :to="`/${locale}`"><b>LOOK</b><span>DO</span></RouterLink><p>© 2026 LOOKDO. {{ tr('footer') }}</p></div><div class="footer-links"><RouterLink :to="`/${locale}/impressum`">{{ tr('impressum') }}</RouterLink><RouterLink :to="`/${locale}/datenschutz`">{{ tr('privacy') }}</RouterLink><RouterLink :to="`/${locale}/agb`">{{ tr('terms') }}</RouterLink><RouterLink :to="`/${locale}/widerruf`">{{ tr('withdrawal') }}</RouterLink><RouterLink :to="`/${locale}/kontakt`">{{ tr('contact') }}</RouterLink></div></footer>
  </div>
</template>

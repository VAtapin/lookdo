<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api } from '../api';
import { setLocale, tr, type Locale } from '../i18n';

const tenant = ref<any>(null);
const error = ref('');
const theme = computed(() => ({
    '--tenant-primary': tenant.value?.template?.preview?.primary_color || '#ff6b00',
    '--tenant-secondary': tenant.value?.template?.preview?.secondary_color || '#25282e',
}));

onMounted(async () => {
    try {
        tenant.value = await api('/tenant-site');
        if (['de', 'en', 'ru', 'uk'].includes(tenant.value.locale)) setLocale(tenant.value.locale as Locale);
    } catch (exception: any) {
        error.value = exception.message;
    }
});
</script>

<template>
  <div class="tenant-public-site" :style="theme">
    <header class="tenant-public-header"><div class="tenant-wordmark wide"><img :src="'/brand/lookdo-logo.png'" alt="LOOKDO"></div><small>{{ tr('poweredBy') }}</small></header>
    <main v-if="tenant" class="tenant-public-main">
      <div class="tenant-public-visual"><img :src="tenant.template.preview.image" :alt="tenant.template.name"></div>
      <section class="tenant-public-copy">
        <p class="eyebrow">{{ tenant.template.category }}</p>
        <h1>{{ tenant.name }}</h1>
        <h2>{{ tenant.template.name }}</h2>
        <p>{{ tenant.description }}</p>
        <div class="tenant-public-notice"><b>{{ tr('tenantAppPreparing') }}</b><span>{{ tr('tenantAppPreparingText') }}</span></div>
      </section>
    </main>
    <div v-else-if="error" class="tenant-public-error">{{ error }}</div>
    <div v-else class="loading">LOOKDO…</div>
  </div>
</template>

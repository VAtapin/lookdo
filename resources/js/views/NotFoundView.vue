<script setup lang="ts">
import { tr } from '../i18n';
</script>

<template>
  <main class="not-found-page">
    <img decoding="async" :src="'/brand/lookdo-mark.png'" alt="LOOKDO">
    <p>404</p>
    <h1>{{ tr('pageNotFound') }}</h1>
    <RouterLink class="button" to="/">{{ tr('returnHome') }}</RouterLink>
  </main>
</template>

<style scoped>
.not-found-page{min-height:70vh;display:grid;place-content:center;justify-items:center;gap:14px;padding:32px;text-align:center}.not-found-page img{width:68px;height:68px;border-radius:18px}.not-found-page p{margin:0;color:#ff6500;font-weight:800;letter-spacing:.14em}.not-found-page h1{max-width:620px;margin:0 0 12px;font-size:clamp(30px,6vw,58px);line-height:1.05}
</style>
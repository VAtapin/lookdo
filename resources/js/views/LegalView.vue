<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';import { useRoute } from 'vue-router';import { api } from '../api';
const route=useRoute();const page=ref<any>(null);const load=async()=>page.value=await api(`/platform/pages/${route.params.key}`);onMounted(load);watch(()=>route.params.key,load);
</script>
<template><section class="section legal-page"><p class="eyebrow">LOOKDO</p><h1>{{page?.title}}</h1><div class="legal-content" v-html="page?.content"></div></section></template>

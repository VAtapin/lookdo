<script setup lang="ts">
import { computed, ref } from 'vue';

const props = defineProps<{before:string;after:string;beforeLabel:string;afterLabel:string;alt?:string}>();
const position = ref(50);
const clip = computed(() => `inset(0 ${100-position.value}% 0 0)`);
</script>

<template>
  <div class="ta-before-after" :style="{'--split': position+'%'}">
    <img class="ta-after-image" loading="lazy" decoding="async" :src="after" :alt="alt||afterLabel">
    <img class="ta-before-image" loading="lazy" decoding="async" :src="before" :alt="alt||beforeLabel" :style="{clipPath:clip}">
    <span class="ta-ba-label ta-ba-before">{{beforeLabel}}</span>
    <span class="ta-ba-label ta-ba-after">{{afterLabel}}</span>
    <span class="ta-ba-divider" aria-hidden="true"><i>↔</i></span>
    <input v-model.number="position" type="range" min="0" max="100" :aria-label="`${beforeLabel} / ${afterLabel}`">
  </div>
</template>

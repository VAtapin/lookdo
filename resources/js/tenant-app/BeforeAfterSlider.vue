<script setup lang="ts">
import { computed, ref } from 'vue';

const props = defineProps<{before:string;after:string;beforeLabel:string;afterLabel:string;alt?:string}>();
const emit = defineEmits<{ open: [payload: { src: string; alt: string }] }>();
const position = ref(50);
const clip = computed(() => `inset(0 ${100-position.value}% 0 0)`);
const pointerStart = ref(0);
const positionStart = ref(50);

function openSelected(event: PointerEvent) {
  if (Math.abs(event.clientX - pointerStart.value) > 5) return;
  const rect = (event.currentTarget as HTMLInputElement).getBoundingClientRect();
  const percent = ((event.clientX - rect.left) / rect.width) * 100;
  const beforeSelected = percent <= positionStart.value;
  emit('open', {
    src: beforeSelected ? props.before : props.after,
    alt: `${props.alt || ''} — ${beforeSelected ? props.beforeLabel : props.afterLabel}`.trim(),
  });
}
</script>

<template>
  <div class="ta-before-after" :style="{'--split': position+'%'}">
    <img class="ta-after-image" loading="lazy" decoding="async" :src="after" :alt="alt||afterLabel">
    <img class="ta-before-image" loading="lazy" decoding="async" :src="before" :alt="alt||beforeLabel" :style="{clipPath:clip}">
    <span class="ta-ba-label ta-ba-before">{{beforeLabel}}</span>
    <span class="ta-ba-label ta-ba-after">{{afterLabel}}</span>
    <span class="ta-ba-divider" aria-hidden="true"><i>↔</i></span>
    <input v-model.number="position" type="range" min="0" max="100" :aria-label="`${beforeLabel} / ${afterLabel}`" @pointerdown="pointerStart=$event.clientX;positionStart=position" @pointerup="openSelected">
  </div>
</template>

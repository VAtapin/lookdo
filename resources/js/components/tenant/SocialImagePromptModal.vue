<script setup lang="ts">
import { computed, ref } from 'vue';
import { locale, tr } from '../../i18n';

const props = defineProps<{ prompt: string; busy: boolean; error: string; status: any; context: any }>();
const emit = defineEmits<{ close: []; generate: []; buy: [quantity: number]; 'update:prompt': [value: string] }>();
const quantity = ref(1);
const promptModel = computed({ get: () => props.prompt, set: value => emit('update:prompt', value) });
const total = computed(() => Number(props.status?.unit_price || 0) * quantity.value);
const canGenerate = computed(() => Boolean(props.status?.can_generate));
function quotaText(){return tr('freeImagesRemaining').replace(':remaining',String(props.status?.remaining_free ?? 0)).replace(':limit',String(props.status?.free_limit ?? 0))}
function money(value:number){return new Intl.NumberFormat(locale.value,{style:'currency',currency:props.status?.currency||'EUR'}).format(value)}
</script>

<template>
<div class="image-prompt-backdrop" @click.self="!busy && emit('close')">
  <section class="image-prompt-modal">
    <header><div><p class="eyebrow">{{ tr('imagePromptEyebrow') }}</p><h2>{{ tr('checkImagePrompt') }}</h2></div><button type="button" :aria-label="tr('close')" :disabled="busy" @click="emit('close')">×</button></header>
    <div class="image-prompt-context"><span><b>{{ tr('selectedIndustry') }}</b>{{ context?.selected_category || '—' }}</span><span><b>{{ tr('enteredActivity') }}</b>{{ context?.business_description || '—' }}</span></div>
    <label class="image-prompt-field">{{ tr('imagePromptLabel') }}<textarea v-model="promptModel" rows="8" :disabled="busy" :placeholder="tr('preparingPrompt')"></textarea></label>
    <p v-if="error" class="alert error image-prompt-error" role="alert">{{ error }}</p>
    <p class="image-prompt-warning">{{ tr('checkPromptBeforeGenerate') }}</p>
    <div class="image-credit-status"><div><strong>{{ quotaText() }}</strong><small>{{ tr('purchasedCredits') }}: {{ status?.credits ?? 0 }}</small></div><span>{{ tr('additionalImagePrice') }} <b>{{ money(Number(status?.unit_price || 0)) }}</b></span></div>
    <div v-if="!canGenerate" class="image-credit-purchase"><div><b>{{ tr('freeLimitReached') }}</b><small>{{ tr('buyCreditsText') }}</small></div><label>{{ tr('creditQuantity') }}<input v-model.number="quantity" type="number" min="1" max="20"></label><button type="button" class="button" :disabled="busy" @click="emit('buy', quantity)">{{ tr('buyCredits') }} · {{ money(total) }}</button></div>
    <footer><button type="button" class="button ghost" :disabled="busy" @click="emit('close')">{{ tr('cancel') }}</button><button type="button" class="button" :disabled="busy || !canGenerate || prompt.trim().length < 40" @click="emit('generate')">{{ busy ? tr('pleaseWait') : tr('generateAfterCheck') }}</button></footer>
  </section>
</div>
</template>
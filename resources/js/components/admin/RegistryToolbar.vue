<script setup lang="ts">
defineProps<{ search: string; total?: number; addLabel?: string; busy?: boolean }>();
const emit = defineEmits<{ 'update:search': [value: string]; add: []; refresh: [] }>();
</script>

<template>
    <div class="registry-toolbar">
        <div class="registry-search"><span>⌕</span><input :value="search" placeholder="Suchen…" @input="emit('update:search', ($event.target as HTMLInputElement).value)"></div>
        <div class="registry-filters"><slot /></div>
        <span v-if="total !== undefined" class="registry-total">{{ total }} Einträge</span>
        <button type="button" class="icon-action" :disabled="busy" title="Aktualisieren" @click="emit('refresh')">↻</button>
        <button v-if="addLabel" type="button" class="button registry-add" @click="emit('add')">＋ {{ addLabel }}</button>
    </div>
</template>

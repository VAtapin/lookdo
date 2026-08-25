<script setup lang="ts">
defineProps<{ current: number; last: number; from?: number | null; to?: number | null; total?: number }>();
const emit = defineEmits<{ change: [page: number] }>();
</script>

<template>
    <footer class="registry-pagination">
        <span>{{ from || 0 }}–{{ to || 0 }} von {{ total || 0 }}</span>
        <div><button type="button" :disabled="current <= 1" @click="emit('change', current - 1)">←</button><b>{{ current }} / {{ Math.max(last, 1) }}</b><button type="button" :disabled="current >= last" @click="emit('change', current + 1)">→</button></div>
    </footer>
</template>

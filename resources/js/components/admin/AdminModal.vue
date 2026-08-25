<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';

const props = withDefaults(defineProps<{ title: string; wide?: boolean }>(), { wide: false });
const emit = defineEmits<{ close: [] }>();
const onKey = (event: KeyboardEvent) => event.key === 'Escape' && emit('close');
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div class="admin-modal-backdrop" @mousedown.self="emit('close')">
            <section class="admin-modal" :class="{ wide: props.wide }" role="dialog" aria-modal="true" :aria-label="title">
                <header><h2>{{ title }}</h2><button type="button" aria-label="Schließen" @click="emit('close')">×</button></header>
                <div class="admin-modal-body"><slot /></div>
            </section>
        </div>
    </Teleport>
</template>

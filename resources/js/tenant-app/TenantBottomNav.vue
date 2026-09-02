<script setup lang="ts">
import AppIcon from "./AppIcon.vue";

defineProps<{
    screen: string;
    items: Array<{
        key: string;
        icon: string;
        label: string;
        central?: boolean;
    }>;
    label: string;
}>();
defineEmits<{ navigate: [screen: string] }>();
</script>

<template>
    <nav
        class="ta-bottom-nav"
        :aria-label="label"
        style="grid-template-columns: repeat(5, minmax(0, 1fr))"
    >
        <button
            v-for="item in items"
            :key="item.key"
            :class="{ active: screen === item.key, central: item.central }"
            @click="$emit('navigate', item.key)"
        >
            <span><AppIcon :name="item.icon" /></span
            ><small>{{ item.label }}</small>
        </button>
    </nav>
</template>

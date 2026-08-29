<script setup lang="ts">
import AppIcon from "./AppIcon.vue";

defineProps<{ app: any; copy: Record<string, string> }>();
defineEmits<{ share: [] }>();
</script>

<template>
    <aside class="ta-desktop-aside">
        <img :src="app.tenant.logo || '/brand/lookdo-mark.webp'" alt="" />
        <small>{{ app.tenant.name }}</small>
        <h2>{{ copy.desktopTitle }}</h2>
        <p>{{ copy.desktopText }}</p>
        <div>
            <span><AppIcon name="camera" /></span>
            <span><AppIcon name="message" /></span>
            <span><AppIcon name="calendar" /></span>
        </div>
        <button @click="$emit('share')">
            <AppIcon name="share" />{{ copy.share }}
        </button>
        <em>{{ copy.powered }}</em>
    </aside>
</template>

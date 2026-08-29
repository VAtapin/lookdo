<script setup lang="ts">
import AppIcon from "./AppIcon.vue";

defineProps<{
    copy: Record<string, string>;
    status: string;
    busy: boolean;
}>();
defineEmits<{ enable: []; dismiss: [] }>();
</script>

<template>
    <div class="ta-menu-overlay ta-push-overlay" @click.self="$emit('dismiss')">
        <aside>
            <div class="ta-bell-orbit">
                <AppIcon name="bell" :size="80" /><b>1</b>
            </div>
            <h2>{{ copy.notificationHeadline }}</h2>
            <p>{{ copy.notificationText }}</p>
            <p v-if="status" class="ta-notification-status">{{ status }}</p>
            <button
                class="ta-gold-button"
                :disabled="busy"
                @click="$emit('enable')"
            >
                <AppIcon name="bell" />{{
                    busy ? copy.sending : copy.notifications
                }}
            </button>
            <button class="ta-outline-button" @click="$emit('dismiss')">
                {{ copy.later }}
            </button>
        </aside>
    </div>
</template>

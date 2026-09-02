<script setup lang="ts">
import AppIcon from "./AppIcon.vue";

defineProps<{
    copy: Record<string, string>;
    status: string;
    busy: boolean;
    state: string;
}>();
defineEmits<{ enable: []; dismiss: [] }>();
</script>

<template>
    <div class="ta-menu-overlay ta-push-overlay" @click.self="$emit('dismiss')">
        <aside>
            <div class="ta-bell-orbit">
                <AppIcon name="bell" :size="80" /><b>1</b>
            </div>
            <h2>{{ state === 'denied' ? copy.notificationBlockedTitle : state === 'unsupported' ? copy.notificationUnsupported : state === 'subscribed' ? copy.notificationEnabled : copy.notificationHeadline }}</h2>
            <p>{{ state === 'denied' ? copy.notificationBlockedText : state === 'unsupported' ? copy.notificationUnsupportedText : state === 'subscribed' ? copy.notificationEnabledText : state === 'repair' ? copy.notificationRepairText : copy.notificationText }}</p>
            <p v-if="status" class="ta-notification-status">{{ status }}</p>
            <button
                v-if="!['denied', 'unsupported', 'subscribed'].includes(state)"
                class="ta-gold-button"
                :disabled="busy"
                @click="$emit('enable')"
            >
                <AppIcon name="bell" />{{
                    busy ? copy.sending : state === 'repair' ? copy.notificationRepair : copy.notifications
                }}
            </button>
            <button class="ta-outline-button" @click="$emit('dismiss')">
                {{ copy.later }}
            </button>
        </aside>
    </div>
</template>

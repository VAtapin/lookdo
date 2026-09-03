<script setup lang="ts">
import AppIcon from "./AppIcon.vue";

defineProps<{ copy: Record<string, string>; state: string }>();
defineEmits<{ open: []; dismiss: [] }>();
</script>

<template>
    <aside class="ta-push-nudge">
        <span><AppIcon name="bell" /></span>
        <div><b>{{ state === 'install_required' ? copy.notificationInstallRequired : copy.notificationNudgeTitle }}</b><small>{{ state === 'install_required' ? copy.notificationInstallNudgeText : copy.notificationNudgeText }}</small></div>
        <button class="ta-push-nudge-open" @click="$emit('open')">{{ state === 'install_required' ? copy.installNow : copy.enable }}</button>
        <button class="ta-push-nudge-close" :aria-label="copy.close" @click="$emit('dismiss')"><AppIcon name="close" :size="17" /></button>
    </aside>
</template>

<style scoped>
.ta-push-nudge-open {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 0 16px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 11px;
    background: var(--ta-primary, #e0aa50);
    color: #090a0b;
    font: inherit;
    font-weight: 850;
    line-height: 1;
    white-space: nowrap;
    cursor: pointer;
}

@media (max-width: 560px) {
    .ta-push-nudge {
        grid-template-columns: 42px minmax(0, 1fr);
        align-items: start;
    }

    .ta-push-nudge-open {
        grid-column: 2;
        justify-self: start;
        margin-top: 4px;
    }
}
</style>

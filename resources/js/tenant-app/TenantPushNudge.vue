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

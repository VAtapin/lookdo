<script setup lang="ts">
import { computed } from "vue";
import AppIcon from "./AppIcon.vue";

const props = defineProps<{
    copy: Record<string, string>;
    platform: "ios" | "android" | "desktop";
    canInstall: boolean;
    installed: boolean;
}>();

defineEmits<{ close: []; install: [] }>();

const steps = computed(() => {
    const value = props.copy[`installSteps_${props.platform}`] || "";
    return value.split("|").filter(Boolean);
});
</script>

<template>
    <div class="ta-menu-overlay ta-install-overlay" @click.self="$emit('close')">
        <aside>
            <header>
                <span class="ta-install-mark"><AppIcon name="install" :size="30" /></span>
                <div><b>{{ copy.installTitle }}</b><small>{{ copy.installText }}</small></div>
                <button :aria-label="copy.close" @click="$emit('close')"><AppIcon name="close" /></button>
            </header>
            <p v-if="installed" class="ta-install-state"><AppIcon name="check" />{{ copy.installInstalled }}</p>
            <template v-else>
                <button v-if="canInstall" class="ta-gold-button ta-install-now" @click="$emit('install')">
                    <AppIcon name="install" />{{ copy.installNow }}
                </button>
                <ol>
                    <li v-for="(step, index) in steps" :key="step"><b>{{ index + 1 }}</b><span>{{ step }}</span></li>
                </ol>
            </template>
        </aside>
    </div>
</template>

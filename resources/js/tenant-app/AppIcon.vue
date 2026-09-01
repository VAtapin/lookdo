<script setup lang="ts">
defineProps<{ name: string; size?: number }>();
</script>

<template>
  <svg class="ta-icon" :width="size || 24" :height="size || 24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <template v-if="name==='home'"><path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10M9 20v-6h6v6"/></template>
    <template v-else-if="name==='works' || name==='grid'"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></template>
    <template v-else-if="name==='camera'"><path d="M4 7h3l1.5-2h7L17 7h3v12H4z"/><circle cx="12" cy="13" r="4"/></template>
    <template v-else-if="name==='plus'"><path d="M12 5v14M5 12h14"/></template>
    <template v-else-if="name==='message' || name==='activity'"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3 1.5-5A8 8 0 1 1 21 15Z"/></template>
    <template v-else-if="name==='profile' || name==='user'"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></template>
    <template v-else-if="name==='bell'"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></template>
    <template v-else-if="name==='arrow'"><path d="M5 12h14M14 7l5 5-5 5"/></template>
    <template v-else-if="name==='back'"><path d="m15 18-6-6 6-6"/></template>
    <template v-else-if="name==='close'"><path d="m6 6 12 12M18 6 6 18"/></template>
    <template v-else-if="name==='clock'"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></template>
    <template v-else-if="name==='shield'"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-5"/></template>
    <template v-else-if="name==='star'"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/></template>
    <template v-else-if="name==='tool'"><path d="M14 6a4 4 0 0 0-5-4l2 3-3 3-3-2a4 4 0 0 0 5 5l8 8 3-3-8-8"/></template>
    <template v-else-if="name==='measure'"><path d="m3 17 14-14 4 4L7 21z"/><path d="m14 6 4 4M11 9l2 2M8 12l2 2M5 15l2 2"/></template>
    <template v-else-if="name==='phone'"><path d="M7 3h4l2 5-3 2a16 16 0 0 0 4 4l2-3 5 2v4c0 2-2 4-4 4A14 14 0 0 1 3 7c0-2 2-4 4-4Z"/></template>
    <template v-else-if="name==='share'"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4"/></template>
    <template v-else-if="name==='install'"><path d="M12 3v12M7 10l5 5 5-5"/><path d="M5 19h14"/></template>
    <template v-else-if="name==='check'"><path d="m4 12 5 5L20 6"/></template>
    <template v-else-if="name==='calendar'"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></template>
    <template v-else-if="name==='menu'"><path d="M4 6h16M4 12h16M4 18h16"/></template>
    <template v-else-if="name==='info'"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/></template>
    <template v-else-if="name==='image'"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8" cy="9" r="2"/><path d="m21 15-5-5L5 20"/></template>
    <template v-else-if="name==='rotate'"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M7 8a7 7 0 0 1 11-1l2 2M17 16a7 7 0 0 1-11 1l-2-2"/></template>
    <template v-else-if="name==='steering'"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/><path d="M3.5 10h17M12 14.5V21M8.5 10l3.5 4.5 3.5-4.5"/></template>
    <template v-else-if="name==='send'"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></template>
    <template v-else-if="name==='heart'"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></template>
    <template v-else-if="name==='map'"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3Z"/><path d="M9 3v15M15 6v15"/></template>
    <template v-else-if="name==='location'"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></template>
    <template v-else><circle cx="12" cy="12" r="8"/></template>
  </svg>
</template>

<script setup lang="ts">
defineProps<{ name: string }>();
</script>

<template>
  <svg class="line-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <template v-if="name === 'phone'"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M10 18h4M9 5h6"/></template>
    <template v-else-if="name === 'photo'"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m4 17 5-4 3 2 3-3 5 5"/></template>
    <template v-else-if="name === 'bell'"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></template>
    <template v-else-if="name === 'chat'"><path d="M21 11a8 8 0 0 1-8 8H6l-4 3 1-6a8 8 0 1 1 18-5Z"/><path d="M8 11h.01M12 11h.01M16 11h.01"/></template>
    <template v-else-if="name === 'shield'"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-5"/></template>
    <template v-else-if="name === 'bolt'"><path d="m13 2-9 12h7l-1 8 10-13h-7V2Z"/></template>
    <template v-else-if="name === 'lock'"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></template>
    <template v-else-if="name === 'globe'"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></template>
    <template v-else-if="name === 'star'"><path d="m12 2 3 6 7 .9-5 4.8 1.3 7-6.3-3.4-6.3 3.4 1.3-7-5-4.8L9 8l3-6Z"/></template>
    <template v-else-if="name === 'car'"><path d="m5 11 2-5h10l2 5M3 11h18v7H3zM6 18v2M18 18v2"/><circle cx="7" cy="14" r="1"/><circle cx="17" cy="14" r="1"/></template>
    <template v-else-if="name === 'door'"><path d="M5 22V3h13v19M5 22h15M9 6h6v16M12 14h.01"/></template>
    <template v-else-if="name === 'tools'"><path d="m14.7 6.3 3-3a4 4 0 0 1-5 5L5 16l-2 5 5-2 7.7-7.7a4 4 0 0 1 5-5l-3 3-3-3Z"/><path d="m5 16 3 3"/></template>
    <template v-else-if="name === 'washer'"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M4 7h16"/><circle cx="12" cy="14" r="5"/><path d="M7 5h.01M10 5h.01"/></template>
    <template v-else-if="name === 'sofa'"><path d="M5 12V9a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3v3M3 12a2 2 0 0 1 2 2v4h14v-4a2 2 0 1 1 2 0v6H3v-6a2 2 0 0 1 0-2Z"/></template>
    <template v-else-if="name === 'leaf'"><path d="M12 22V9M12 15C7 15 4 12 4 7c5 0 8 3 8 8ZM12 12c0-5 3-8 8-8 0 5-3 8-8 8Z"/></template>
    <template v-else-if="name === 'calendar'"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 2v6M17 2v6M3 10h18M7 14h3M14 14h3M7 18h3"/></template>
    <template v-else-if="name === 'home'"><path d="m3 11 9-8 9 8"/><path d="M5 10v11h14V10M9 21v-7h6v7"/></template>
    <template v-else-if="name === 'grid'"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></template>
    <template v-else-if="name === 'user'"><circle cx="12" cy="8" r="4"/><path d="M4 22a8 8 0 0 1 16 0"/></template>
    <template v-else><path d="M4 19h16M7 19v-5M17 19v-8M10 14l7-7M14 7h3v3"/></template>
  </svg>
</template>

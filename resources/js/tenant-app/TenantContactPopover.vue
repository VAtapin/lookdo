<script setup lang="ts">
import { computed } from "vue";
import AppIcon from "./AppIcon.vue";

const props = defineProps<{ contact: any; copy: Record<string, string> }>();
defineEmits<{ close: [] }>();

const links = computed(() => [
    props.contact.phone && { key: "phone", label: props.copy.call, detail: props.contact.phone, href: `tel:${props.contact.phone}`, icon: "phone" },
    props.contact.whatsapp_url && { key: "whatsapp", label: "WhatsApp", href: props.contact.whatsapp_url, badge: "WA" },
    props.contact.max_url && { key: "max", label: "MAX", href: props.contact.max_url, badge: "MAX" },
    props.contact.telegram_url && { key: "telegram", label: "Telegram", href: props.contact.telegram_url, badge: "TG" },
    props.contact.viber_url && { key: "viber", label: "Viber", href: props.contact.viber_url, badge: "VI" },
    props.contact.vk_url && { key: "vk", label: "VK", href: props.contact.vk_url, badge: "VK" },
    props.contact.instagram_url && { key: "instagram", label: "Instagram", href: props.contact.instagram_url, badge: "IG" },
    props.contact.facebook_url && { key: "facebook", label: "Facebook", href: props.contact.facebook_url, badge: "FB" },
    props.contact.email && { key: "email", label: "E-mail", detail: props.contact.email, href: `mailto:${props.contact.email}`, badge: "@" },
    props.contact.website_url && { key: "website", label: props.copy.website || "Website", href: props.contact.website_url, badge: "WEB" },
].filter(Boolean) as any[]);
</script>

<template>
    <div class="ta-contact-popover">
        <button class="ta-contact-popover-close" @click="$emit('close')"><AppIcon name="close" :size="18" /></button>
        <b>{{ copy.contacts }}</b>
        <a v-for="item in links" :key="item.key" :href="item.href" :target="item.key === 'phone' || item.key === 'email' ? undefined : '_blank'" rel="noopener noreferrer">
            <AppIcon v-if="item.icon" :name="item.icon" />
            <strong v-else>{{ item.badge }}</strong>
            <span>{{ item.label }}<small v-if="item.detail">{{ item.detail }}</small></span>
        </a>
    </div>
</template>

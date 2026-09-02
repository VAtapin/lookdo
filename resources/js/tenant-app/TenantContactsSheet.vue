<script setup lang="ts">
import AppIcon from "./AppIcon.vue";

defineProps<{
    app: any;
    copy: Record<string, string>;
    address: string;
    contactName: string;
}>();
defineEmits<{ close: [] }>();
</script>

<template>
    <section class="ta-page ta-contact-background">
        <div class="ta-contacts-sheet">
            <header>
                <h1>{{ copy.contacts }}</h1>
                <button @click="$emit('close')">
                    <AppIcon name="close" />
                </button>
            </header>
            <p>{{ copy.contactText }}</p>
            <a
                v-if="app.tenant.contact.phone"
                :href="'tel:' + app.tenant.contact.phone"
            >
                <span><AppIcon name="phone" /></span>
                <div>
                    <b>{{ copy.call }} {{ contactName }}</b
                    ><em>{{ app.tenant.contact.phone }}</em>
                </div>
                <AppIcon name="arrow" />
            </a>
            <a
                v-if="app.tenant.contact.whatsapp_url"
                :href="app.tenant.contact.whatsapp_url"
                target="_blank"
            >
                <span><b>WA</b></span><div><b>WhatsApp</b></div><AppIcon name="arrow" />
            </a>
            <a
                v-if="app.tenant.contact.max_url"
                :href="app.tenant.contact.max_url"
                target="_blank"
            >
                <span><b>MAX</b></span>
                <div><b>MAX</b><em>{{ app.tenant.contact.max_url }}</em></div>
                <AppIcon name="arrow" />
            </a>
            <a
                v-if="app.tenant.contact.telegram_url"
                :href="app.tenant.contact.telegram_url"
                target="_blank"
            >
                <span><b>TG</b></span><div><b>Telegram</b></div><AppIcon name="arrow" />
            </a>
            <a
                v-if="app.tenant.contact.viber_url"
                :href="app.tenant.contact.viber_url"
                target="_blank"
            >
                <span><b>VI</b></span><div><b>Viber</b></div><AppIcon name="arrow" />
            </a>
            <a
                v-if="app.tenant.contact.vk_url"
                :href="app.tenant.contact.vk_url"
                target="_blank"
            >
                <span><b>VK</b></span>
                <div>
                    <b>{{ copy.socialContact }}</b
                    ><em>{{ app.tenant.contact.vk_url }}</em>
                </div>
                <AppIcon name="arrow" />
            </a>
            <a
                v-if="app.tenant.contact.instagram_url"
                :href="app.tenant.contact.instagram_url"
                target="_blank"
            >
                <span><b>IG</b></span><div><b>Instagram</b></div><AppIcon name="arrow" />
            </a>
            <a
                v-if="app.tenant.contact.facebook_url"
                :href="app.tenant.contact.facebook_url"
                target="_blank"
            >
                <span><b>FB</b></span><div><b>Facebook</b></div><AppIcon name="arrow" />
            </a>
            <a v-if="app.tenant.contact.email" :href="'mailto:' + app.tenant.contact.email">
                <span><b>@</b></span><div><b>E-mail</b><em>{{ app.tenant.contact.email }}</em></div><AppIcon name="arrow" />
            </a>
            <a
                v-if="app.tenant.contact.website_url"
                :href="app.tenant.contact.website_url"
                target="_blank"
            >
                <span><b>WEB</b></span><div><b>Website</b></div><AppIcon name="arrow" />
            </a>
            <a
                v-if="address"
                :href="
                    'https://www.google.com/maps/search/?api=1&query=' +
                    encodeURIComponent(address)
                "
                target="_blank"
            >
                <span><AppIcon name="map" /></span>
                <div>
                    <b>{{ copy.workshopAddress }}</b
                    ><em>{{ address }}</em>
                </div>
                <AppIcon name="arrow" />
            </a>
            <article v-if="app.tenant.contact.working_hours">
                <AppIcon name="clock" />
                <div>
                    <b>{{ copy.workingHours }}</b>
                    <p>{{ app.tenant.contact.working_hours }}</p>
                </div>
            </article>
            <button class="ta-outline-button" @click="$emit('close')">
                {{ copy.back }}
            </button>
        </div>
    </section>
</template>

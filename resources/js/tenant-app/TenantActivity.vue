<script setup lang="ts">
import AppIcon from "./AppIcon.vue";

defineProps<{
    app: any;
    copy: Record<string, string>;
    activity: { requests: any[]; appointments: any[] };
    loading: boolean;
    actionScreen: string;
    locale: string;
    selected: any;
    message: string;
    sending: boolean;
    statusLabel: (status: string) => string;
}>();
const emit = defineEmits<{
    navigate: [screen: string];
    select: [request: any];
    reschedule: [appointment: any];
    cancel: [appointment: any];
    send: [];
    "update:message": [message: string];
}>();
</script>

<template>
    <section class="ta-page ta-activity-page">
        <div class="ta-page-title">
            <small>{{ app.tenant.name }}</small>
            <h1>{{ copy.activity }}</h1>
        </div>
        <div v-if="loading" class="ta-loading-line"></div>
        <div
            v-else-if="
                !activity.requests.length && !activity.appointments.length
            "
            class="ta-empty"
        >
            <span><AppIcon name="message" :size="42" /></span>
            <h2>{{ copy.noActivity }}</h2>
            <p>{{ copy.noActivityText }}</p>
            <button
                class="ta-gold-button"
                @click="$emit('navigate', actionScreen)"
            >
                {{ app.template.hero.action }}
            </button>
        </div>
        <template v-else>
            <div class="ta-activity-list">
                <button
                    v-for="item in activity.requests"
                    :key="'r' + item.id"
                    @click="$emit('select', item)"
                >
                    <span class="ta-activity-icon"
                        ><AppIcon name="camera"
                    /></span>
                    <span>
                        <b>#{{ item.number }}</b>
                        <small>{{
                            new Date(item.created_at).toLocaleString(locale, {
                                dateStyle: "medium",
                                timeStyle: "short",
                            })
                        }}</small>
                        <em>{{ item.messages.at(-1)?.body }}</em>
                    </span>
                    <i>{{ statusLabel(item.status) }}</i>
                </button>
                <article
                    v-for="item in activity.appointments"
                    :key="'a' + item.id"
                    class="ta-appointment-card"
                >
                    <span class="ta-activity-icon"
                        ><AppIcon name="calendar"
                    /></span>
                    <span>
                        <b>{{ item.service?.name }}</b>
                        <small>{{
                            new Date(item.starts_at).toLocaleString(locale, {
                                dateStyle: "long",
                                timeStyle: "short",
                            })
                        }}</small>
                        <em>#{{ item.number }}</em>
                    </span>
                    <i>{{ statusLabel(item.status) }}</i>
                    <footer
                        v-if="
                            !['cancelled', 'completed', 'no_show'].includes(
                                item.status,
                            )
                        "
                    >
                        <button @click="$emit('reschedule', item)">
                            {{ copy.reschedule }}
                        </button>
                        <button @click="$emit('cancel', item)">
                            {{ copy.cancelAppointment }}
                        </button>
                    </footer>
                </article>
            </div>
            <div v-if="selected" class="ta-thread">
                <header>
                    <button @click="$emit('select', null)">
                        <AppIcon name="back" />
                    </button>
                    <span
                        ><b>#{{ selected.number }}</b
                        ><small>{{ copy.messages }}</small></span
                    >
                </header>
                <div class="ta-thread-messages">
                    <p
                        v-for="item in selected.messages"
                        :key="item.id"
                        :class="item.sender"
                    >
                        <span>{{ item.body }}</span>
                        <small>{{
                            new Date(item.created_at).toLocaleTimeString(
                                locale,
                                { hour: "2-digit", minute: "2-digit" },
                            )
                        }}</small>
                    </p>
                </div>
                <form @submit.prevent="$emit('send')">
                    <input
                        :value="message"
                        :placeholder="copy.messagePlaceholder"
                        @input="
                            $emit(
                                'update:message',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <button :disabled="sending || !message.trim()">
                        <AppIcon name="send" />
                    </button>
                </form>
            </div>
        </template>
    </section>
</template>

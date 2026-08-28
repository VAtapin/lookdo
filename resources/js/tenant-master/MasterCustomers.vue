<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '../api';

const props = defineProps<{ tenantId: number; locale: string; t: (key: string) => string }>();
const items = ref<any[]>([]);
const selected = ref<any>(null);
const history = ref<any>({ requests: [], appointments: [], messages: [] });
const search = ref('');
const busy = ref(false);
const error = ref('');

async function load() {
    busy.value = true;
    error.value = '';
    try {
        const result: any = await api(`/tenant/${props.tenantId}/workspace/customers?search=${encodeURIComponent(search.value)}`);
        items.value = result.items.data;
        if (selected.value) {
            const fresh = items.value.find((item) => item.id === selected.value.id);
            if (fresh) await choose(fresh);
            else selected.value = null;
        }
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}

async function choose(item: any) {
    busy.value = true;
    error.value = '';
    try {
        const result: any = await api(`/tenant/${props.tenantId}/workspace/customers/${item.id}`);
        selected.value = { ...result.customer, tags: result.customer.tags || [] };
        history.value = result;
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}

async function save() {
    if (!selected.value) return;
    busy.value = true;
    error.value = '';
    try {
        const result: any = await api(`/tenant/${props.tenantId}/workspace/customers/${selected.value.id}`, {
            method: 'PUT',
            body: JSON.stringify({
                name: selected.value.name,
                phone: selected.value.phone,
                email: selected.value.email,
                preferred_channel: selected.value.preferred_channel,
                notes: selected.value.notes,
                tags: selected.value.tags,
                marketing_consent: Boolean(selected.value.marketing_consent_at),
                publication_consent: Boolean(selected.value.publication_consent_at),
            }),
        });
        selected.value = { ...result.customer, tags: result.customer.tags || [] };
        await load();
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}

async function merge() {
    if (!selected.value?.possible_duplicate || !confirm(props.t('confirmMerge'))) return;
    busy.value = true;
    error.value = '';
    try {
        const result: any = await api(`/tenant/${props.tenantId}/workspace/customers/${selected.value.possible_duplicate.id}/merge`, {
            method: 'POST',
            body: JSON.stringify({ source_id: selected.value.id }),
        });
        await load();
        await choose(result.customer);
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}

const localName = (service: any) => service?.name?.[props.locale] || service?.name?.de || Object.values(service?.name || {})[0] || '—';
const localDate = (value: string) => value ? new Date(value).toLocaleString(props.locale) : '—';

onMounted(load);
</script>

<template>
    <section class="mw-stack">
        <header class="mw-page-head">
            <div><p class="mw-kicker">CRM</p><h1>{{ t('customers') }}</h1></div>
        </header>
        <div class="mw-toolbar">
            <input v-model="search" :placeholder="t('search')" @keyup.enter="load">
            <button class="mw-primary" @click="load">{{ t('search') }}</button>
        </div>
        <p v-if="error" class="mw-error">{{ error }}</p>
        <div class="mw-inbox">
            <div class="mw-inbox-list">
                <button v-for="item in items" :key="item.id" :class="{ active: selected?.id === item.id }" @click="choose(item)">
                    <span class="mw-avatar">{{ (item.name || '?').slice(0, 1) }}</span>
                    <span><b>{{ item.name || item.phone }}</b><small>{{ item.phone }} · {{ item.requests_count }} {{ t('requests') }}</small></span>
                    <em v-if="item.possible_duplicate">!</em>
                </button>
                <p v-if="!items.length" class="mw-empty">{{ t('noItems') }}</p>
            </div>
            <form v-if="selected" class="mw-thread mw-form" @submit.prevent="save">
                <header><div><p class="mw-kicker">{{ t('profile') }}</p><h2>{{ selected.name || selected.phone }}</h2></div><button type="button" class="mw-thread-close" @click="selected = null">×</button></header>
                <p v-if="selected.possible_duplicate" class="mw-warning">
                    <b>{{ t('possibleDuplicate') }}</b>
                    <span>{{ selected.possible_duplicate.name }} · {{ selected.possible_duplicate.phone }}</span>
                    <button type="button" @click="merge">{{ t('merge') }}</button>
                </p>
                <div class="mw-customer-verification">
                    <span>{{ t('phoneVerified') }}: <b>{{ selected.phone_verified_at ? t('yes') : t('no') }}</b></span>
                    <span>{{ t('emailVerified') }}: <b>{{ selected.email_verified_at ? t('yes') : t('no') }}</b></span>
                </div>
                <label>{{ t('customer') }}<input v-model="selected.name"></label>
                <label>{{ t('contact') }}<input v-model="selected.phone"></label>
                <label>{{ t('email') }}<input v-model="selected.email" type="email"></label>
                <label>{{ t('channel') }}
                    <select v-model="selected.preferred_channel">
                        <option v-for="channel in ['phone','push','vk','whatsapp','sms','email']" :key="channel" :value="channel">{{ channel === 'vk' ? 'VK' : t(channel === 'phone' ? 'phoneChannel' : channel === 'email' ? 'emailChannel' : channel) }}</option>
                    </select>
                </label>
                <label>{{ t('tags') }}<input :value="(selected.tags || []).join(', ')" @input="selected.tags = ($event.target as HTMLInputElement).value.split(',').map((value) => value.trim()).filter(Boolean)"></label>
                <label>{{ t('notes') }}<textarea v-model="selected.notes" rows="4"></textarea></label>
                <label class="mw-check"><input v-model="selected.marketing_consent_at" type="checkbox" true-value="1" false-value="">{{ t('marketingConsent') }}</label>
                <label class="mw-check"><input v-model="selected.publication_consent_at" type="checkbox" true-value="1" false-value="">{{ t('publicationConsent') }}</label>
                <button class="mw-primary" :disabled="busy">{{ t('save') }}</button>

                <section class="mw-history">
                    <h3>{{ t('customerHistory') }}</h3>
                    <article v-for="entry in history.appointments" :key="'appointment-'+entry.id">
                        <b>{{ t('appointment') }} · {{ localDate(entry.starts_at) }}</b>
                        <small>{{ localName(entry.service) }} · {{ t(entry.status) }}</small>
                    </article>
                    <article v-for="entry in history.requests" :key="'request-'+entry.id">
                        <b>#{{ entry.number }} · {{ entry.summary || t('request') }}</b>
                        <small>{{ localDate(entry.created_at) }} · {{ t(entry.status) }}</small>
                    </article>
                    <article v-for="entry in history.messages" :key="'message-'+entry.id">
                        <b>{{ t(entry.sender_type === 'customer' ? 'customer' : 'team') }}</b>
                        <small>{{ entry.body }}</small>
                    </article>
                    <p v-if="!history.appointments?.length && !history.requests?.length && !history.messages?.length" class="mw-empty">{{ t('noHistory') }}</p>
                </section>
            </form>
            <article v-else class="mw-thread mw-empty">{{ t('selectCustomer') }}</article>
        </div>
    </section>
</template>

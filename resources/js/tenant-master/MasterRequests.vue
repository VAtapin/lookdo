<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from "vue";
import { api } from "../api";
const props = defineProps<{
    tenantId: number;
    mode: "requests" | "messages";
    locale: string;
    entitlements?: Record<string, string>;
    t: (key: string) => string;
}>();
const emit = defineEmits<{ unreadChanged: [count: number] }>();
const hasAi = computed(
    () => String(props.entitlements?.ai_communication_enabled || "0") === "1",
);
const items = ref<any[]>([]),
    selected = ref<any>(null),
    search = ref(""),
    status = ref(""),
    reply = ref(""),
    note = ref(""),
    busy = ref(false),
    error = ref(""),
    messageList = ref<HTMLElement | null>(null),
    detailsExpanded = ref(false),
    previewMedia = ref<any>(null);
async function scrollMessages() {
    await nextTick();
    if (messageList.value)
        messageList.value.scrollTop = messageList.value.scrollHeight;
}
async function load() {
    busy.value = true;
    error.value = "";
    try {
        if (props.mode === "messages") {
            const r: any = await api(
                `/tenant/${props.tenantId}/workspace/conversations`,
            );
            items.value = r.items;
        } else {
            const q = new URLSearchParams();
            if (search.value) q.set("search", search.value);
            if (status.value) q.set("status", status.value);
            const r: any = await api(
                `/tenant/${props.tenantId}/workspace/requests?${q}`,
            );
            const requests = (r.items.data || []).map((item: any) => ({
                ...item,
                kind: "request",
            }));
            items.value = [...requests, ...(r.appointments || [])].sort(
                (a: any, b: any) =>
                    new Date(b.created_at || b.starts_at).getTime() -
                    new Date(a.created_at || a.starts_at).getTime(),
            );
        }
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function choose(item: any) {
    selected.value = props.mode === "messages" ? item.request : item;
    detailsExpanded.value = false;
    note.value = selected.value.internal_note || "";
    if (
        selected.value.kind !== "appointment" &&
        props.mode === "requests" &&
        !selected.value.messages
    )
        await loadDetail(item.id);
    if (selected.value.kind !== "appointment") await markRead();
    scrollMessages();
}
async function loadDetail(id: number) {
    const r: any = await api(
        `/tenant/${props.tenantId}/workspace/requests?search=`,
    );
    const found = r.items.data.find((x: any) => x.id === id);
    if (found) selected.value = found;
}
async function markRead() {
    if (!selected.value) return;
    try {
        const r: any = await api(
            `/tenant/${props.tenantId}/workspace/requests/${selected.value.id}/read`,
            { method: "POST" },
        );
        for (const message of selected.value.messages || []) {
            if (message.sender_type === "customer" && !message.read_at)
                message.read_at = r.read_at;
        }
        const row = items.value.find(
            (x: any) =>
                (props.mode === "messages" ? x.request.id : x.id) ===
                selected.value.id,
        );
        if (props.mode === "messages" && row) row.unread = 0;
        emit("unreadChanged", Number(r.unread || 0));
    } catch (e: any) {
        error.value = e.message;
    }
}
function detailValue(value: any) {
    if (value === null || value === undefined || value === "")
        return props.t("notFilled");
    if (Array.isArray(value))
        return value.length ? value.join(", ") : props.t("notFilled");
    if (typeof value === "object")
        return (
            Object.values(value).filter(Boolean).join(", ") ||
            props.t("notFilled")
        );
    return String(value);
}
async function saveStatus(value: string) {
    if (!selected.value) return;
    busy.value = true;
    try {
        if (selected.value.kind === "appointment") {
            const r: any = await api(
                `/tenant/${props.tenantId}/workspace/appointments/${selected.value.id}`,
                { method: "PUT", body: JSON.stringify({ status: value }) },
            );
            selected.value = r.appointment;
        } else {
            const r: any = await api(
                `/tenant/${props.tenantId}/workspace/requests/${selected.value.id}`,
                {
                    method: "PUT",
                    body: JSON.stringify({
                        status: value,
                        internal_note: note.value,
                    }),
                },
            );
            selected.value = { ...r.request, kind: "request" };
        }
        await load();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function suggest() {
    if (!selected.value) return;
    busy.value = true;
    error.value = "";
    try {
        const r: any = await api(`/tenant/${props.tenantId}/workspace/ai`, {
            method: "POST",
            body: JSON.stringify({
                task: "reply",
                locale: props.locale,
                request_id: selected.value.id,
                internal_note: note.value,
            }),
        });
        reply.value = r.text;
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function send() {
    if (
        !selected.value ||
        selected.value.kind === "appointment" ||
        !reply.value.trim()
    )
        return;
    busy.value = true;
    error.value = "";
    const body = reply.value.trim();
    try {
        const r: any = await api(
            `/tenant/${props.tenantId}/workspace/requests/${selected.value.id}/reply`,
            {
                method: "POST",
                body: JSON.stringify({
                    body,
                    event:
                        selected.value.status === "completed"
                            ? "work_ready"
                            : "master_replied",
                }),
            },
        );
        selected.value.messages = selected.value.messages || [];
        selected.value.messages.push(r.message);
        reply.value = "";
        const row = items.value.find(
            (x: any) =>
                (props.mode === "messages" ? x.request.id : x.id) ===
                selected.value.id,
        );
        if (row) {
            if (props.mode === "messages") {
                row.request.messages = selected.value.messages;
                row.last_message = r.message;
                row.unread = 0;
            } else row.messages = selected.value.messages;
        }
        await scrollMessages();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
function serviceName(item: any) {
    const name = item.service?.name;
    return typeof name === "string"
        ? name
        : name?.[props.locale] || name?.de || name?.en || "";
}
function rowKey(item: any) {
    const value =
        props.mode === "messages" && item.request ? item.request : item;
    return `${value.kind || "request"}-${value.id}`;
}
function closePreview() {
    previewMedia.value = null;
}
function handleEscape(event: KeyboardEvent) {
    if (event.key === "Escape") closePreview();
}
watch(() => props.mode, load);
onMounted(() => {
    load();
    window.addEventListener("keydown", handleEscape);
});
onBeforeUnmount(() => window.removeEventListener("keydown", handleEscape));
</script>
<template>
    <section class="mw-stack">
        <header class="mw-page-head">
            <div>
                <p class="mw-kicker">LOOKDO</p>
                <h1>{{ t(mode) }}</h1>
            </div>
        </header>
        <div class="mw-toolbar">
            <input
                v-model="search"
                :placeholder="t('search')"
                @keyup.enter="load"
            /><select
                v-if="mode === 'requests'"
                v-model="status"
                @change="load"
            >
                <option value="">{{ t("allStatuses") }}</option>
                <option
                    v-for="s in [
                        'new',
                        'viewed',
                        'in_progress',
                        'waiting',
                        'pending',
                        'confirmed',
                        'completed',
                        'cancelled',
                        'no_show',
                    ]"
                    :key="s"
                    :value="s"
                >
                    {{ t(s) }}
                </option>
            </select>
        </div>
        <p v-if="error" class="mw-error">{{ error }}</p>
        <div class="mw-inbox">
            <div class="mw-inbox-list">
                <button
                    v-for="entry in items"
                    :key="rowKey(entry)"
                    :class="{
                        active: selected && rowKey(selected) === rowKey(entry),
                    }"
                    @click="choose(entry)"
                >
                    <span class="mw-avatar">{{
                        (
                            (mode === "messages"
                                ? entry.customer
                                : entry.customer
                            )?.name || "?"
                        ).slice(0, 1)
                    }}</span
                    ><span
                        ><b>{{
                            (mode === "messages"
                                ? entry.customer
                                : entry.customer
                            )?.name ||
                            (mode === "messages"
                                ? entry.customer
                                : entry.customer
                            )?.phone
                        }}</b
                        ><small>{{
                            mode === "messages"
                                ? entry.last_message?.body ||
                                  entry.request.summary
                                : entry.kind === "appointment"
                                  ? `${t("appointment")}: ${serviceName(entry)}`
                                  : entry.summary || entry.number
                        }}</small></span
                    ><em v-if="mode === 'messages' && entry.unread">{{
                        entry.unread
                    }}</em
                    ><em v-else>{{
                        t((mode === "messages" ? entry.request : entry).status)
                    }}</em>
                </button>
                <p v-if="!items.length && !busy" class="mw-empty">
                    {{ t("noItems") }}
                </p>
            </div>
            <article v-if="selected" class="mw-thread">
                <header>
                    <button class="mw-thread-close" @click="selected = null">
                        ×
                    </button>
                    <div>
                        <p class="mw-kicker">{{ selected.number }}</p>
                        <h2>
                            {{
                                selected.customer?.name ||
                                selected.customer?.phone
                            }}
                        </h2>
                        <p>
                            {{
                                selected.kind === "appointment"
                                    ? `${t("appointment")}: ${serviceName(selected)}`
                                    : selected.summary
                            }}
                        </p>
                    </div>
                    <select
                        :value="selected.status"
                        @change="
                            saveStatus(
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option
                            v-for="s in selected.kind === 'appointment'
                                ? [
                                      'pending',
                                      'confirmed',
                                      'completed',
                                      'cancelled',
                                      'no_show',
                                  ]
                                : [
                                      'new',
                                      'viewed',
                                      'in_progress',
                                      'waiting',
                                      'completed',
                                      'cancelled',
                                  ]"
                            :key="s"
                            :value="s"
                        >
                            {{ t(s) }}
                        </option>
                    </select>
                </header>
                <section
                    v-if="selected.kind === 'appointment'"
                    class="mw-request-details"
                >
                    <h3>{{ t("appointment") }}</h3>
                    <dl>
                        <div>
                            <dt>{{ t("service") }}</dt>
                            <dd>{{ serviceName(selected) }}</dd>
                        </div>
                        <div>
                            <dt>{{ t("date") }}</dt>
                            <dd>
                                {{
                                    new Date(selected.starts_at).toLocaleString(
                                        locale,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt>{{ t("serviceLocation") }}</dt>
                            <dd>
                                {{
                                    [
                                        t(selected.service_mode || "workshop"),
                                        selected.service_address,
                                    ]
                                        .filter(Boolean)
                                        .join(" · ")
                                }}
                            </dd>
                        </div>
                    </dl>
                </section>
                <div v-if="selected.media?.length" class="mw-media">
                    <button
                        v-for="media in selected.media"
                        :key="media.id"
                        type="button"
                        @click="previewMedia = media"
                    >
                        <img
                            v-if="media.type === 'image'"
                            :src="media.url"
                        /><span v-else>▶</span>
                    </button>
                </div>
                <section v-if="selected.ai_assessment" class="mw-ai-assessment">
                    <span>AI</span>
                    <div>
                        <h3>{{ t("aiConditionAssessment") }}</h3>
                        <p>{{ selected.ai_assessment }}</p>
                    </div>
                </section>
                <section
                    v-if="selected.details?.length"
                    class="mw-request-details"
                >
                    <div class="mw-request-details-head">
                        <h3>{{ t("requestDetails") }}</h3>
                        <button
                            type="button"
                            class="mw-details-toggle"
                            @click="detailsExpanded = !detailsExpanded"
                        >
                            {{
                                detailsExpanded
                                    ? t("hideDetails")
                                    : t("showAllDetails")
                            }}
                        </button>
                    </div>
                    <dl
                        :class="{
                            'mw-mobile-collapsed': !detailsExpanded,
                        }"
                    >
                        <div
                            v-for="field in selected.details"
                            :key="field.key"
                            :class="{
                                'detail-empty':
                                    detailValue(field.value) === t('notFilled'),
                            }"
                        >
                            <dt>{{ field.label }}</dt>
                            <dd
                                :class="{
                                    empty:
                                        detailValue(field.value) ===
                                        t('notFilled'),
                                }"
                            >
                                {{ detailValue(field.value) }}
                            </dd>
                        </div>
                    </dl>
                </section>
                <div
                    v-if="selected.kind !== 'appointment'"
                    ref="messageList"
                    class="mw-messages"
                >
                    <p
                        v-for="message in selected.messages"
                        :key="message.id"
                        :class="message.sender_type || message.sender"
                    >
                        <span>{{ message.body }}</span
                        ><time>{{
                            new Date(message.created_at).toLocaleString(locale)
                        }}</time>
                    </p>
                </div>
                <label v-if="selected.kind !== 'appointment'"
                    >{{ t("internalNote")
                    }}<textarea v-model="note" rows="3"></textarea></label
                ><button
                    class="mw-secondary"
                    @click="saveStatus(selected.status)"
                >
                    {{ t("save") }}
                </button>
                <form
                    v-if="selected.kind !== 'appointment'"
                    class="mw-reply"
                    @submit.prevent="send"
                >
                    <textarea
                        v-model="reply"
                        rows="3"
                        :placeholder="t('reply')"
                    ></textarea>
                    <div class="mw-reply-actions">
                        <button
                            v-if="hasAi"
                            type="button"
                            class="mw-secondary"
                            :disabled="busy"
                            @click="suggest"
                        >
                            {{ t("aiReply") }}</button
                        ><button
                            type="submit"
                            :disabled="busy || !reply.trim()"
                        >
                            {{ t("send") }}
                        </button>
                    </div>
                </form>
            </article>
            <article v-else class="mw-thread mw-empty">
                {{ t("noItems") }}
            </article>
        </div>
        <Teleport to="body">
            <div
                v-if="previewMedia"
                class="mw-media-modal"
                role="dialog"
                aria-modal="true"
                @click.self="closePreview"
            >
                <button
                    type="button"
                    class="mw-media-modal-close"
                    :aria-label="t('closePreview')"
                    @click="closePreview"
                >
                    ×
                </button>
                <img
                    v-if="previewMedia.type === 'image'"
                    :src="previewMedia.url"
                    :alt="t('requestDetails')"
                />
                <video v-else :src="previewMedia.url" controls autoplay></video>
            </div>
        </Teleport>
    </section>
</template>

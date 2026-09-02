<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from "vue";
import { api } from "../api";
import { buildMonthGrid, eventsOnDay } from "./calendar-utils";
import ResourceManager from "./ResourceManager.vue";
const props = defineProps<{
    tenantId: number;
    locale: string;
    t: (key: string) => string;
    initialTab?: string;
}>();
const initialQuery = new URLSearchParams(window.location.search);
const data = ref<any>({
        appointments: [],
        blocks: [],
        services: [],
        working_hours: [],
        reminders: [],
        customers: [],
        resources: [],
        entitlements: {},
    }),
    tab = ref(initialQuery.get("tab") || props.initialTab || "calendar"),
    busy = ref(false),
    error = ref("");
const month = ref(new Date().toISOString().slice(0, 7)),
    day = ref(new Date().toISOString().slice(0, 10));
const appointment = reactive<any>({
    id: null,
    customer_id: "",
    service_id: "",
    starts_at: "",
    status: "confirmed",
    comment: "",
    reminder_at: "",
    resource_id: "",
    service_mode: "workshop",
    service_address: "",
});
const block = reactive<any>({
    kind: "blocked",
    reason: "",
    starts_at: "",
    ends_at: "",
    all_day: false,
    resource_id: "",
});
const service = reactive<any>({
    id: null,
    name: { de: "", en: "", ru: "", uk: "" },
    description: {},
    image_path: null,
    image: null,
    duration_minutes: 60,
    buffer_before_minutes: 0,
    buffer_after_minutes: 0,
    repeat_interval_days: null,
    price: null,
    currency: "EUR",
    booking_enabled: true,
    media_allowed: true,
    active: true,
    sort_order: 0,
});
const servicePreview = ref("");
const reminder = reactive<any>({
    customer_id: "",
    appointment_id: "",
    type: initialQuery.get("type") || "appointment",
    channel: "push",
    scheduled_at: "",
    message: "",
});
const enabled = (key: string, fallback = false) =>
    String(data.value.entitlements?.[key] ?? (fallback ? "1" : "0")) === "1";
const hasReminders = computed(() => enabled("reminders_enabled")),
    hasAi = computed(() => enabled("ai_communication_enabled")),
    hasSms = computed(() => enabled("sms_enabled"));
const reminderChannels = computed(() => [
    "push",
    ...(hasSms.value ? ["sms"] : []),
    "emailChannel",
    "whatsapp",
]);
const tabs = computed(() => [
    "calendar",
    "resources",
    "workingHours",
    "services",
    ...(hasReminders.value ? ["reminders"] : []),
]);
const serviceTitle = computed({
    get: () => service.name[props.locale] || "",
    set: (value: string) => {
        service.name[props.locale] = value;
    },
});
const serviceDescription = computed({
    get: () => service.description[props.locale] || "",
    set: (value: string) => {
        service.description[props.locale] = value;
    },
});
const events = computed(() =>
    [
        ...data.value.appointments.map((x: any) => ({
            ...x,
            eventType: "appointment",
        })),
        ...data.value.blocks.map((x: any) => ({ ...x, eventType: "block" })),
    ].sort((a, b) => String(a.starts_at).localeCompare(String(b.starts_at))),
);
const todayEvents = computed(() => eventsOnDay(events.value, day.value));
const monthGrid = computed(() => buildMonthGrid(month.value));
const eventCount = (date: string) => eventsOnDay(events.value, date).length;
const selectMonthDay = (date: string | null) => {
    if (date) day.value = date;
};
const breakValue = (entry: any, key: "start" | "end") =>
    entry.breaks?.[0]?.[key] || "";
const setBreak = (entry: any, key: "start" | "end", value: string) => {
    const current = entry.breaks?.[0] || { start: "", end: "" };
    entry.breaks =
        value || current[key === "start" ? "end" : "start"]
            ? [{ ...current, [key]: value }]
            : [];
};
async function load() {
    busy.value = true;
    error.value = "";
    try {
        const from = month.value + "-01";
        const d = new Date(`${from}T00:00:00`);
        d.setMonth(d.getMonth() + 1);
        const to = d.toISOString().slice(0, 10);
        data.value = await api(
            `/tenant/${props.tenantId}/calendar?from=${from}&to=${to}`,
        );
        if (
            !hasReminders.value &&
            ["reminders", "reminder"].includes(tab.value)
        )
            tab.value = "calendar";
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function saveHours() {
    busy.value = true;
    try {
        const r: any = await api(
            `/tenant/${props.tenantId}/calendar/working-hours`,
            {
                method: "PUT",
                body: JSON.stringify({
                    days: data.value.working_hours.map((x: any) => ({
                        ...x,
                        starts_at: x.starts_at?.slice(0, 5),
                        ends_at: x.ends_at?.slice(0, 5),
                    })),
                }),
            },
        );
        data.value.working_hours = r.working_hours;
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function saveService() {
    busy.value = true;
    try {
        const url = service.id
            ? `/tenant/${props.tenantId}/services/${service.id}`
            : `/tenant/${props.tenantId}/services`;
        const saved: any = await api(url, {
            method: service.id ? "PUT" : "POST",
            body: JSON.stringify({ ...service, image: undefined }),
        });
        if (service.image) {
            const body = new FormData();
            body.append("image", service.image);
            await api(
                `/tenant/${props.tenantId}/services/${saved.service.id}/image`,
                { method: "POST", body },
            );
        }
        resetService();
        await load();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
function clearServicePreview() {
    if (servicePreview.value.startsWith("blob:"))
        URL.revokeObjectURL(servicePreview.value);
    servicePreview.value = "";
}
function serviceImageUrl(item: any) {
    const path = item?.image_url || item?.image_path || "";
    return !path
        ? ""
        : path.startsWith("/") || path.startsWith("http")
          ? path
          : `/storage/${path}`;
}
function localDescription(item: any) {
    return item.description?.[props.locale] || "";
}
function resetService() {
    clearServicePreview();
    Object.assign(service, {
        id: null,
        name: { de: "", en: "", ru: "", uk: "" },
        description: {},
        image_path: null,
        image: null,
        duration_minutes: 60,
        buffer_before_minutes: 0,
        buffer_after_minutes: 0,
        repeat_interval_days: null,
        price: null,
        currency: "EUR",
        booking_enabled: true,
        media_allowed: true,
        active: true,
        sort_order: 0,
    });
}
function editService(item: any) {
    clearServicePreview();
    Object.assign(service, {
        ...item,
        name: { de: "", en: "", ru: "", uk: "", ...(item.name || {}) },
        description: { ...(item.description || {}) },
        image: null,
    });
    servicePreview.value = serviceImageUrl(item);
}
function setServiceImage(event: Event) {
    clearServicePreview();
    const file = (event.target as HTMLInputElement).files?.[0] || null;
    service.image = file;
    servicePreview.value = file
        ? URL.createObjectURL(file)
        : serviceImageUrl(service);
}
async function removeServiceImage() {
    if (!confirm(props.t("confirmDelete"))) return;
    if (!service.id) {
        service.image = null;
        service.image_path = null;
        clearServicePreview();
        return;
    }
    busy.value = true;
    try {
        await api(`/tenant/${props.tenantId}/services/${service.id}/image`, {
            method: "DELETE",
        });
        service.image = null;
        service.image_path = null;
        clearServicePreview();
        await load();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function deleteService() {
    if (!service.id || !confirm(props.t("confirmDelete"))) return;
    try {
        await api(`/tenant/${props.tenantId}/services/${service.id}`, {
            method: "DELETE",
        });
        resetService();
        await load();
    } catch (e: any) {
        error.value = e.message;
    }
}
async function saveAppointment() {
    busy.value = true;
    error.value = "";
    try {
        const url = appointment.id
            ? `/tenant/${props.tenantId}/calendar/appointments/${appointment.id}`
            : `/tenant/${props.tenantId}/calendar/appointments`;
        await api(url, {
            method: appointment.id ? "PUT" : "POST",
            body: JSON.stringify({
                ...appointment,
                customer_id: appointment.customer_id || null,
                resource_id: appointment.resource_id || null,
                reminder_at: hasReminders.value
                    ? appointment.reminder_at || null
                    : null,
            }),
        });
        resetAppointment();
        await load();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function deleteAppointment() {
    if (!appointment.id || !confirm(props.t("confirmDelete"))) return;
    await api(
        `/tenant/${props.tenantId}/calendar/appointments/${appointment.id}`,
        { method: "DELETE" },
    );
    resetAppointment();
    await load();
}
async function saveBlock() {
    busy.value = true;
    try {
        await api(`/tenant/${props.tenantId}/calendar/blocks`, {
            method: "POST",
            body: JSON.stringify(block),
        });
        Object.assign(block, {
            kind: "blocked",
            reason: "",
            starts_at: "",
            ends_at: "",
            all_day: false,
            resource_id: "",
        });
        tab.value = "calendar";
        await load();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function deleteBlock(id: number) {
    if (!confirm(props.t("confirmDelete"))) return;
    await api(`/tenant/${props.tenantId}/calendar/blocks/${id}`, {
        method: "DELETE",
    });
    await load();
}
async function saveReminder() {
    busy.value = true;
    try {
        await api(`/tenant/${props.tenantId}/calendar/reminders`, {
            method: "POST",
            body: JSON.stringify({
                ...reminder,
                customer_id: reminder.customer_id || null,
                appointment_id: reminder.appointment_id || null,
            }),
        });
        Object.assign(reminder, {
            customer_id: "",
            appointment_id: "",
            type: "appointment",
            channel: "push",
            scheduled_at: "",
            message: "",
        });
        tab.value = "reminders";
        await load();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function deleteReminder(id: number) {
    if (!confirm(props.t("confirmDelete"))) return;
    try {
        await api(`/tenant/${props.tenantId}/calendar/reminders/${id}`, {
            method: "DELETE",
        });
        await load();
    } catch (e: any) {
        error.value = e.message;
    }
}
async function aiReminder() {
    if (!reminder.message.trim()) reminder.message = props.t("reminder");
    try {
        const r: any = await api(`/tenant/${props.tenantId}/workspace/ai`, {
            method: "POST",
            body: JSON.stringify({
                task:
                    reminder.type === "repeat_visit"
                        ? "repeat_visit"
                        : reminder.type === "vacancy"
                          ? "vacancy"
                          : "reminder",
                locale: props.locale,
                context: reminder.message,
            }),
        });
        reminder.message = r.text;
    } catch (e: any) {
        error.value = e.message;
    }
}
function resetAppointment() {
    Object.assign(appointment, {
        id: null,
        customer_id: "",
        service_id: "",
        starts_at: "",
        status: "confirmed",
        comment: "",
        reminder_at: "",
        resource_id: "",
        service_mode: "workshop",
        service_address: "",
    });
    tab.value = "calendar";
}
function localInput(value: string) {
    const d = new Date(value);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}
function editEvent(event: any) {
    if (event.eventType === "block") return;
    Object.assign(appointment, {
        id: event.id,
        customer_id: event.customer_id || "",
        service_id: event.service_id || "",
        starts_at: localInput(event.starts_at),
        status: event.status,
        comment: event.comment || "",
        reminder_at: event.reminder_at ? localInput(event.reminder_at) : "",
        resource_id: event.resource_id || "",
        service_mode: event.contact_snapshot?.service_mode || "workshop",
        service_address: event.contact_snapshot?.service_address || "",
    });
    tab.value = "appointment";
}
const weekdayLabel = (index: string | number) =>
    props.t("weekdays").split(",")[Number(index)] || "";
const localName = (s: any) =>
    s?.name?.[props.locale] ||
    s?.name?.de ||
    Object.values(s?.name || {})[0] ||
    "—";
const customerName = (c: any) => c?.name || c?.phone || "—";
const time = (v: string) =>
    new Intl.DateTimeFormat(props.locale, {
        hour: "2-digit",
        minute: "2-digit",
    }).format(new Date(v));
onMounted(load);
onUnmounted(clearServicePreview);
</script>
<template>
    <section class="mw-stack">
        <header class="mw-page-head">
            <div>
                <p class="mw-kicker">LOOKDO</p>
                <h1>
                    {{ t(initialTab === "services" ? "services" : "calendar") }}
                </h1>
            </div>
        </header>
        <nav class="mw-tabs">
            <button
                v-for="x in tabs"
                :key="x"
                :class="{ active: tab === x }"
                @click="tab = x"
            >
                {{ t(x) }}
            </button>
        </nav>
        <p v-if="error" class="mw-error">{{ error }}</p>
        <div v-if="tab === 'calendar'" class="mw-calendar-layout">
            <article class="mw-panel">
                <header>
                    <input v-model="month" type="month" @change="load" /><button
                        class="mw-primary"
                        @click="
                            resetAppointment();
                            tab = 'appointment';
                        "
                    >
                        + {{ t("appointment") }}
                    </button>
                </header>
                <div class="mw-month-grid">
                    <b v-for="label in t('weekdays').split(',')" :key="label">{{
                        label
                    }}</b
                    ><button
                        v-for="(date, index) in monthGrid"
                        :key="date || 'blank-' + index"
                        :disabled="!date"
                        :class="{
                            selected: date === day,
                            today:
                                date === new Date().toISOString().slice(0, 10),
                            busy: date && eventCount(date) > 0,
                        }"
                        @click="selectMonthDay(date)"
                    >
                        <span v-if="date">{{ Number(date.slice(-2)) }}</span
                        ><em v-if="date && eventCount(date)">{{
                            eventCount(date)
                        }}</em>
                    </button>
                </div>
                <label
                    >{{ t("date") }}<input v-model="day" type="date"
                /></label>
                <div class="mw-agenda">
                    <article
                        v-for="event in todayEvents"
                        :key="event.eventType + '-' + event.id"
                        :class="event.eventType"
                        @click="editEvent(event)"
                    >
                        <time>{{ time(event.starts_at) }}</time
                        ><span
                            ><b>{{
                                event.eventType === "appointment"
                                    ? customerName(event.customer)
                                    : event.reason || t(event.kind)
                            }}</b
                            ><small>{{
                                event.eventType === "appointment"
                                    ? [
                                          localName(event.service),
                                          event.contact_snapshot?.service_mode
                                              ? t(
                                                    event.contact_snapshot
                                                        .service_mode,
                                                )
                                              : null,
                                          event.contact_snapshot
                                              ?.service_address,
                                          event.resource?.name,
                                      ]
                                          .filter(Boolean)
                                          .join(" · ")
                                    : t(event.kind)
                            }}</small></span
                        ><em>{{
                            event.eventType === "appointment"
                                ? t(event.status)
                                : t("blocked")
                        }}</em
                        ><button
                            v-if="event.eventType === 'block'"
                            class="mw-danger-link"
                            @click.stop="deleteBlock(event.id)"
                        >
                            {{ t("delete") }}
                        </button>
                    </article>
                    <p v-if="!todayEvents.length" class="mw-empty">
                        {{ t("calendarEmpty") }}
                    </p>
                </div>
                <footer>
                    <button class="mw-secondary" @click="tab = 'block'">
                        + {{ t("addBlock") }}</button
                    ><button
                        v-if="hasReminders"
                        class="mw-secondary"
                        @click="tab = 'reminder'"
                    >
                        + {{ t("addReminder") }}
                    </button>
                </footer>
            </article>
            <aside class="mw-panel">
                <h2>{{ t("appointmentsToday") }}</h2>
                <button
                    v-for="event in events.slice(0, 12)"
                    :key="event.eventType + 's' + event.id"
                    class="mw-mini-event"
                    @click="editEvent(event)"
                >
                    <time>{{
                        new Date(event.starts_at).toLocaleString(props.locale)
                    }}</time
                    ><b>{{
                        event.eventType === "appointment"
                            ? customerName(event.customer)
                            : event.reason || t(event.kind)
                    }}</b>
                </button>
            </aside>
        </div>
        <form
            v-if="tab === 'appointment'"
            class="mw-form mw-panel"
            @submit.prevent="saveAppointment"
        >
            <h2>{{ t(appointment.id ? "edit" : "addAppointment") }}</h2>
            <label
                >{{ t("customer")
                }}<select v-model="appointment.customer_id">
                    <option value="">—</option>
                    <option
                        v-for="c in data.customers"
                        :key="c.id"
                        :value="c.id"
                    >
                        {{ customerName(c) }}
                    </option>
                </select></label
            ><label
                >{{ t("resource")
                }}<select v-model="appointment.resource_id">
                    <option value="">{{ t("anyResource") }}</option>
                    <option
                        v-for="item in data.resources"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name }}
                    </option>
                </select></label
            ><label
                >{{ t("service")
                }}<select v-model="appointment.service_id" required>
                    <option value=""></option>
                    <option
                        v-for="s in data.services"
                        :key="s.id"
                        :value="s.id"
                    >
                        {{ localName(s) }}
                    </option>
                </select></label
            ><label
                >{{ t("serviceLocation")
                }}<select v-model="appointment.service_mode">
                    <option value="workshop">{{ t("workshop") }}</option>
                    <option value="on_site">{{ t("on_site") }}</option>
                </select></label
            ><label v-if="appointment.service_mode === 'on_site'"
                >{{ t("serviceAddress")
                }}<input
                    v-model="appointment.service_address"
                    required
                    :placeholder="t('serviceAddressHint')" /></label
            ><label
                >{{ t("date") }} / {{ t("time")
                }}<input
                    v-model="appointment.starts_at"
                    type="datetime-local"
                    required /></label
            ><label
                >{{ t("status")
                }}<select v-model="appointment.status">
                    <option
                        v-for="s in [
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
                </select></label
            ><label v-if="hasReminders"
                >{{ t("reminder")
                }}<input
                    v-model="appointment.reminder_at"
                    type="datetime-local" /></label
            ><label
                >{{ t("notes")
                }}<textarea v-model="appointment.comment"></textarea></label
            ><button class="mw-primary">{{ t("save") }}</button
            ><button
                v-if="appointment.id"
                type="button"
                class="mw-danger"
                @click="deleteAppointment"
            >
                {{ t("deleteAppointment") }}</button
            ><button
                type="button"
                class="mw-secondary"
                @click="resetAppointment"
            >
                {{ t("close") }}
            </button>
        </form>
        <form
            v-if="tab === 'block'"
            class="mw-form mw-panel"
            @submit.prevent="saveBlock"
        >
            <h2>{{ t("addBlock") }}</h2>
            <label
                >{{ t("status")
                }}<select v-model="block.kind">
                    <option
                        v-for="s in [
                            'blocked',
                            'vacation',
                            'personal',
                            'exception',
                        ]"
                        :key="s"
                        :value="s"
                    >
                        {{ t(s) }}
                    </option>
                </select></label
            ><label
                >{{ t("resource")
                }}<select v-model="block.resource_id">
                    <option value="">{{ t("allResources") }}</option>
                    <option
                        v-for="item in data.resources"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name }}
                    </option>
                </select></label
            ><label
                >{{ t("from")
                }}<input
                    v-model="block.starts_at"
                    type="datetime-local"
                    required /></label
            ><label
                >{{ t("to")
                }}<input
                    v-model="block.ends_at"
                    type="datetime-local"
                    required /></label
            ><label>{{ t("reason") }}<input v-model="block.reason" /></label
            ><button class="mw-primary">{{ t("save") }}</button
            ><button
                type="button"
                class="mw-secondary"
                @click="tab = 'calendar'"
            >
                {{ t("close") }}
            </button>
        </form>
        <ResourceManager
            v-if="tab === 'resources'"
            :tenant-id="tenantId"
            :resources="data.resources"
            :t="t"
            @changed="load"
        />
        <div v-if="tab === 'workingHours'" class="mw-panel">
            <h2>{{ t("workingHours") }}</h2>
            <div class="mw-hours">
                <label
                    v-for="(entry, index) in data.working_hours"
                    :key="entry.weekday"
                    ><input v-model="entry.enabled" type="checkbox" /><b>{{
                        weekdayLabel(index)
                    }}</b
                    ><span class="mw-hours-range"
                        ><input
                            v-model="entry.starts_at"
                            type="time"
                            :disabled="!entry.enabled" /><i>—</i
                        ><input
                            v-model="entry.ends_at"
                            type="time"
                            :disabled="!entry.enabled" /></span
                    ><span class="mw-break-range"
                        ><small>{{ t("breakTime") }}</small
                        ><input
                            :value="breakValue(entry, 'start')"
                            type="time"
                            :disabled="!entry.enabled"
                            @input="
                                setBreak(
                                    entry,
                                    'start',
                                    ($event.target as HTMLInputElement).value,
                                )
                            " /><i>—</i
                        ><input
                            :value="breakValue(entry, 'end')"
                            type="time"
                            :disabled="!entry.enabled"
                            @input="
                                setBreak(
                                    entry,
                                    'end',
                                    ($event.target as HTMLInputElement).value,
                                )
                            " /></span
                ></label>
            </div>
            <button class="mw-primary" @click="saveHours">
                {{ t("save") }}
            </button>
        </div>
        <div v-if="tab === 'services'" class="mw-two">
            <article class="mw-panel">
                <h2>{{ t("services") }}</h2>
                <div
                    v-for="s in data.services"
                    :key="s.id"
                    class="mw-service-row"
                >
                    <img
                        v-if="serviceImageUrl(s)"
                        class="mw-service-thumb"
                        :src="serviceImageUrl(s)"
                        :alt="localName(s)"
                    /><span
                        ><b>{{ localName(s) }}</b
                        ><small
                            v-if="localDescription(s)"
                            class="mw-service-description"
                            >{{ localDescription(s) }}</small
                        ><small
                            >{{ s.duration_minutes }} {{ t("minutes") }} ·
                            {{ s.price || "—" }} {{ s.currency }}</small
                        ></span
                    ><span class="mw-row-actions"
                        ><em>{{ s.active ? t("active") : t("cancelled") }}</em
                        ><button class="mw-secondary" @click="editService(s)">
                            {{ t("edit") }}
                        </button></span
                    >
                </div>
            </article>
            <form class="mw-form mw-panel" @submit.prevent="saveService">
                <h2>{{ t(service.id ? "editService" : "addService") }}</h2>
                <label
                    >{{ t("title")
                    }}<input v-model="serviceTitle" required /></label
                ><label
                    >{{ t("description")
                    }}<textarea
                        v-model="serviceDescription"
                        rows="7"
                    ></textarea></label
                ><label
                    >{{ t("duration")
                    }}<input
                        v-model.number="service.duration_minutes"
                        type="number"
                        min="10"
                /></label>
                <div class="mw-service-media">
                    <span v-if="servicePreview"
                        ><img
                            :src="servicePreview"
                            :alt="t('serviceImage')" /></span
                    ><label class="mw-secondary"
                        >{{ t("chooseImage")
                        }}<input
                            hidden
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            @change="setServiceImage" /></label
                    ><button
                        v-if="servicePreview"
                        type="button"
                        class="mw-danger"
                        @click="removeServiceImage"
                    >
                        {{ t("removeImage") }}</button
                    ><small>{{ t("serviceImageHint") }}</small>
                </div>
                <label
                    >{{ t("bufferBefore")
                    }}<input
                        v-model.number="service.buffer_before_minutes"
                        type="number" /></label
                ><label
                    >{{ t("bufferAfter")
                    }}<input
                        v-model.number="service.buffer_after_minutes"
                        type="number" /></label
                ><label
                    >{{ t("repeatDays")
                    }}<input
                        v-model.number="service.repeat_interval_days"
                        type="number" /></label
                ><label
                    >{{ t("price")
                    }}<input
                        v-model.number="service.price"
                        type="number"
                        step="0.01" /></label
                ><label class="mw-check"
                    ><input
                        v-model="service.booking_enabled"
                        type="checkbox"
                    />{{ t("booking") }}</label
                ><label class="mw-check"
                    ><input v-model="service.media_allowed" type="checkbox" />{{
                        t("media")
                    }}</label
                ><label class="mw-check"
                    ><input v-model="service.active" type="checkbox" />{{
                        t("active")
                    }}</label
                ><button class="mw-primary">{{ t("save") }}</button
                ><button
                    v-if="service.id"
                    type="button"
                    class="mw-danger"
                    @click="deleteService"
                >
                    {{ t("delete") }}</button
                ><button
                    v-if="service.id"
                    type="button"
                    class="mw-secondary"
                    @click="resetService"
                >
                    {{ t("close") }}
                </button>
            </form>
        </div>
        <div v-if="tab === 'reminders' && hasReminders" class="mw-panel">
            <h2>{{ t("reminders") }}</h2>
            <div v-for="r in data.reminders" :key="r.id" class="mw-service-row">
                <span
                    ><b>{{ r.message }}</b
                    ><small
                        >{{
                            new Date(r.scheduled_at).toLocaleString(
                                props.locale,
                            )
                        }}
                        · {{ t(r.channel) }}</small
                    ></span
                ><span class="mw-row-actions"
                    ><em>{{ t(r.status) }}</em
                    ><button
                        v-if="!['sent', 'queued'].includes(r.status)"
                        class="mw-danger-link"
                        @click="deleteReminder(r.id)"
                    >
                        {{ t("delete") }}
                    </button></span
                >
            </div>
            <button class="mw-primary" @click="tab = 'reminder'">
                + {{ t("addReminder") }}
            </button>
        </div>
        <form
            v-if="tab === 'reminder' && hasReminders"
            class="mw-form mw-panel"
            @submit.prevent="saveReminder"
        >
            <h2>{{ t("addReminder") }}</h2>
            <label
                >{{ t("customer")
                }}<select v-model="reminder.customer_id" required>
                    <option value=""></option>
                    <option
                        v-for="c in data.customers"
                        :key="c.id"
                        :value="c.id"
                    >
                        {{ customerName(c) }}
                    </option>
                </select></label
            ><label
                >{{ t("status")
                }}<select v-model="reminder.type">
                    <option value="appointment">{{ t("appointment") }}</option>
                    <option value="agreement">{{ t("reminder") }}</option>
                    <option
                        v-if="enabled('repeat_visit_enabled')"
                        value="repeat_visit"
                    >
                        {{ t("repeatCandidates") }}
                    </option>
                    <option
                        v-if="enabled('vacancy_fill_enabled')"
                        value="vacancy"
                    >
                        {{ t("freeWindows") }}
                    </option>
                </select></label
            ><label
                >{{ t("channel")
                }}<select v-model="reminder.channel">
                    <option
                        v-for="c in reminderChannels"
                        :key="c"
                        :value="c === 'emailChannel' ? 'email' : c"
                    >
                        {{ t(c) }}
                    </option>
                </select></label
            ><label
                >{{ t("date") }} / {{ t("time")
                }}<input
                    v-model="reminder.scheduled_at"
                    type="datetime-local"
                    required /></label
            ><label
                >{{ t("reminder")
                }}<textarea
                    v-model="reminder.message"
                    required
                ></textarea></label
            ><button
                v-if="hasAi"
                type="button"
                class="mw-secondary"
                @click="aiReminder"
            >
                {{ t("aiReminder") }}</button
            ><button class="mw-primary">{{ t("save") }}</button
            ><button
                type="button"
                class="mw-secondary"
                @click="tab = 'reminders'"
            >
                {{ t("close") }}
            </button>
        </form>
    </section>
</template>

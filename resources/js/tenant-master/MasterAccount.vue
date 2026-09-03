<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { api } from "../api";
import LineIcon from "../components/LineIcon.vue";
const props = defineProps<{
    tenantId: number;
    user: any;
    account: any;
    workspace: any;
    plans: any[];
    section: string;
    locale: string;
    t: (key: string) => string;
}>();
const emit = defineEmits<{ reload: []; navigate: [section: string] }>();
const busy = ref(false),
    error = ref(""),
    domain = ref(""),
    cycle = ref("monthly"),
    currency = ref("EUR");
const profile = reactive<any>({}),
    accountForm = reactive({
        name: "",
        email: "",
        current_password: "",
        password: "",
        password_confirmation: "",
    }),
    accountSaved = ref(false),
    deleteForm = reactive({ password: "", confirmation: "" }),
    team = ref<any>({ members: [], limit: 1, can_manage: false }),
    member = reactive({ name: "", email: "", role: "staff" }),
    setupUrl = ref(""),
    copied = ref(false),
    pushStatus = ref("");
const localeOptions = [
    ["de", "Deutsch"],
    ["en", "English"],
    ["ru", "Русский"],
    ["uk", "Українська"],
];
const countryOptions = [
    ["DE", "Deutschland"],
    ["AT", "Österreich"],
    ["CH", "Schweiz"],
    ["RU", "Россия"],
    ["UA", "Україна"],
    ["GB", "United Kingdom"],
];
const timezoneOptions = [
    "Europe/Berlin",
    "Europe/Vienna",
    "Europe/Zurich",
    "Europe/Kyiv",
    "Europe/Moscow",
    "Europe/Kaliningrad",
    "Europe/Samara",
    "Asia/Yekaterinburg",
    "Asia/Omsk",
    "Asia/Krasnoyarsk",
    "Asia/Irkutsk",
    "Asia/Yakutsk",
    "Asia/Vladivostok",
    "Asia/Magadan",
    "Asia/Kamchatka",
];
const entitlementEnabled = (key: string, fallback = false) =>
    String(props.account?.entitlements?.[key] ?? (fallback ? "1" : "0")) ===
    "1";
const hasDomain = computed(() => entitlementEnabled("custom_domain"));
const hasSms = computed(() => entitlementEnabled("sms_enabled"));
const hasPush = computed(() =>
    Boolean(
        props.workspace?.push?.enabled && props.workspace?.push?.public_key,
    ),
);
const currentSubscription = computed(
    () => props.account?.tenant?.current_subscription || null,
);
const currentPlanId = computed(() =>
    Number(
        currentSubscription.value?.plan_id ||
            currentSubscription.value?.plan?.id ||
            0,
    ),
);
watch(
    () => [props.tenantId, currentSubscription.value?.id],
    () => {
        currency.value =
            currentSubscription.value?.currency ||
            (props.locale === "ru"
                ? "RUB"
                : props.locale === "uk"
                  ? "UAH"
                  : "EUR");
        cycle.value = currentSubscription.value?.billing_cycle || "monthly";
    },
    { immediate: true },
);
const moreItems = computed(() => [
    ["account", "user"],
    ["customers", "user"],
    ["work", "photo"],
    ["services", "tools"],
    ["business", "briefcase"],
    ["branding", "photo"],
    ["billing", "card"],
    ["team", "user"],
    ["settings", "grid"],
    ...(hasDomain.value ? [["domain", "globe"]] : []),
]);
watch(
    () => props.account,
    (v) => {
        if (v)
            Object.assign(profile, {
                name: v.tenant.name,
                slug: v.tenant.slug,
                country: v.tenant.country,
                timezone: v.tenant.timezone || "Europe/Berlin",
                locale: v.tenant.locale,
                enabled_locales: v.tenant.profile?.enabled_locales ||
                    v.tenant.profile?.content?.enabled_locales || [
                        v.tenant.locale,
                    ],
                ...(v.tenant.profile || {}),
                notification_preferences: {
                    push: true,
                    sms: false,
                    email: false,
                    ...(v.tenant.profile?.content?.notifications || {}),
                },
            });
    },
    { immediate: true },
);
watch(
    () => props.user,
    (v) => {
        if (v)
            Object.assign(accountForm, {
                name: v.name || "",
                email: v.email || "",
                current_password: "",
                password: "",
                password_confirmation: "",
            });
    },
    { immediate: true },
);
watch(
    () => props.section,
    (s) => {
        if (s === "team") loadTeam();
    },
    { immediate: true },
);
async function saveProfile() {
    busy.value = true;
    error.value = "";
    pushStatus.value = "";
    try {
        await api(`/tenant/${props.tenantId}/profile`, {
            method: "PUT",
            body: JSON.stringify(profile),
        });
        if (hasPush.value)
            await syncPush(Boolean(profile.notification_preferences.push));
        emit("reload");
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function saveSlug() {
    busy.value = true;
    error.value = "";
    try {
        await api(`/tenant/${props.tenantId}/slug`, {
            method: "PUT",
            body: JSON.stringify({ slug: profile.slug }),
        });
        emit("reload");
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function saveAccount() {
    busy.value = true;
    error.value = "";
    accountSaved.value = false;
    try {
        const r: any = await api("/account", {
            method: "PUT",
            body: JSON.stringify(accountForm),
        });
        Object.assign(accountForm, {
            current_password: "",
            password: "",
            password_confirmation: "",
        });
        accountSaved.value = true;
        if (r.email_pending) error.value = props.t("emailConfirmationSent");
        emit("reload");
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
function applicationServerKey(value: string): Uint8Array {
    const padding = "=".repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + padding).replace(/-/g, "+").replace(/_/g, "/");
    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}
async function syncPush(enabled: boolean) {
    if (!("Notification" in window) || !("serviceWorker" in navigator)) {
        pushStatus.value = props.t("pushUnsupported");
        return;
    }
    const registration = await navigator.serviceWorker.ready;
    const current = await registration.pushManager.getSubscription();
    if (!enabled) {
        if (current) {
            await api(
                `/tenant/${props.tenantId}/workspace/push-subscriptions`,
                {
                    method: "DELETE",
                    body: JSON.stringify({ endpoint: current.endpoint }),
                },
            );
            await current.unsubscribe();
        }
        pushStatus.value = props.t("pushDisabled");
        return;
    }
    const permission = await Notification.requestPermission();
    if (permission !== "granted") {
        profile.notification_preferences.push = false;
        pushStatus.value = props.t("pushDenied");
        return;
    }
    const subscription =
        current ||
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey(
                props.workspace.push.public_key,
            ) as BufferSource,
        }));
    const json = subscription.toJSON();
    await api(`/tenant/${props.tenantId}/workspace/push-subscriptions`, {
        method: "POST",
        body: JSON.stringify({
            endpoint: subscription.endpoint,
            keys: json.keys,
        }),
    });
    pushStatus.value = props.t("pushEnabled");
}
async function addDomain() {
    busy.value = true;
    error.value = "";
    try {
        await api(`/tenant/${props.tenantId}/domains`, {
            method: "POST",
            body: JSON.stringify({ domain: domain.value }),
        });
        domain.value = "";
        emit("reload");
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function verifyDomain(id: number) {
    busy.value = true;
    error.value = "";
    try {
        await api(`/tenant/${props.tenantId}/domains/${id}/verify`, {
            method: "POST",
        });
        emit("reload");
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function removeDomain(id: number) {
    if (!confirm(props.t("confirmDelete"))) return;
    busy.value = true;
    error.value = "";
    try {
        await api(`/tenant/${props.tenantId}/domains/${id}`, {
            method: "DELETE",
        });
        emit("reload");
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
const domainStatus = (d: any) => {
    if (d.ssl_status === "active") return props.t("domainActive");
    if (
        d.ssl_status === "failed" ||
        d.provisioning_status === "failed" ||
        d.status === "failed"
    )
        return props.t("domainFailed");
    if (
        d.ssl_status === "configuration_required" ||
        d.provisioning_status === "configuration_required"
    )
        return props.t("domainConfigurationRequired");
    if (d.status === "verifying" || d.provisioning_status === "provisioning")
        return props.t("domainProvisioning");
    if (d.status === "ssl_pending") return props.t("domainSslPending");
    if (d.status === "disabled") return props.t("domainDisabled");
    return props.t("domainDnsPending");
};
async function checkout(planId: number) {
    busy.value = true;
    error.value = "";
    try {
        const r: any = await api(`/tenant/${props.tenantId}/checkout`, {
            method: "POST",
            body: JSON.stringify({
                plan_id: planId,
                cycle: cycle.value,
                currency: currency.value,
            }),
        });
        location.href = r.checkout_url;
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function openBillingPortal() {
    busy.value = true;
    error.value = "";
    try {
        const r: any = await api(`/tenant/${props.tenantId}/billing-portal`, {
            method: "POST",
        });
        location.href = r.url;
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
function openInvoiceDocument(invoice: any) {
    window.open(
        `/api/tenant/${props.tenantId}/invoices/${invoice.id}`,
        "_blank",
        "noopener,noreferrer",
    );
}
function openPaymentReceipt(payment: any) {
    window.open(
        `/api/tenant/${props.tenantId}/payments/${payment.id}/receipt`,
        "_blank",
        "noopener,noreferrer",
    );
}
async function loadTeam() {
    try {
        team.value = await api(`/tenant/${props.tenantId}/workspace/team`);
    } catch (e: any) {
        error.value = e.message;
    }
}
async function addMember() {
    busy.value = true;
    error.value = "";
    try {
        const r: any = await api(`/tenant/${props.tenantId}/workspace/team`, {
            method: "POST",
            body: JSON.stringify(member),
        });
        setupUrl.value = r.setup_url || "";
        Object.assign(member, { name: "", email: "", role: "staff" });
        await loadTeam();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function removeMember(id: number) {
    if (!confirm(props.t("confirmDelete"))) return;
    try {
        await api(`/tenant/${props.tenantId}/workspace/team/${id}`, {
            method: "DELETE",
        });
        await loadTeam();
    } catch (e: any) {
        error.value = e.message;
    }
}
async function updateMember(entry: any) {
    busy.value = true;
    error.value = "";
    try {
        await api(`/tenant/${props.tenantId}/workspace/team/${entry.id}`, {
            method: "PUT",
            body: JSON.stringify(entry),
        });
        await loadTeam();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function memberSetup(entry: any) {
    busy.value = true;
    error.value = "";
    try {
        const r: any = await api(
            `/tenant/${props.tenantId}/workspace/team/${entry.id}/setup-link`,
            { method: "POST" },
        );
        setupUrl.value = r.setup_url || "";
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function copySetup() {
    if (!setupUrl.value) return;
    await navigator.clipboard.writeText(setupUrl.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1800);
}
function exportData() {
    window.location.href = `/api/tenant/${props.tenantId}/export`;
}
async function deleteOwnAccount() {
    if (!confirm(props.t("deleteAccountWarning"))) return;
    busy.value = true;
    error.value = "";
    try {
        await api(`/tenant/${props.tenantId}/account`, {
            method: "DELETE",
            body: JSON.stringify(deleteForm),
        });
        localStorage.removeItem("lookdo-master-tenant");
        location.href = "/login";
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
const localizedPlanText = (value: any, fallback = "") => {
    if (typeof value === "string") return value;
    return value?.[props.locale] || value?.de || value?.en || fallback;
};
const planName = (plan: any) => localizedPlanText(plan?.name, plan?.code || "");
const planDescription = (plan: any) => localizedPlanText(plan?.description, "");
const isCurrentPlan = (plan: any) => Number(plan?.id) === currentPlanId.value;
const planPrice = (plan: any) => {
    const configured = plan?.prices?.[currency.value]?.[cycle.value];
    if (configured !== null && configured !== undefined && configured !== "")
        return Number(configured);
    if (String(plan?.currency).toUpperCase() === currency.value) {
        const legacy =
            cycle.value === "yearly" ? plan?.price_yearly : plan?.price_monthly;
        return legacy === null || legacy === undefined ? null : Number(legacy);
    }
    return null;
};
const formatPlanPrice = (plan: any) => {
    const value = planPrice(plan);
    if (value === null || Number.isNaN(value))
        return props.t("priceUnavailable");
    const numberLocale =
        props.locale === "ru"
            ? "ru-RU"
            : props.locale === "uk"
              ? "uk-UA"
              : props.locale === "de"
                ? "de-DE"
                : "en-GB";
    return new Intl.NumberFormat(numberLocale, {
        style: "currency",
        currency: currency.value,
        maximumFractionDigits: value % 1 ? 2 : 0,
    }).format(value);
};
const planFeatures = (plan: any) => {
    const important = new Set([
        "requests",
        "storage",
        "staff",
        "languages",
        "video",
        "custom_domain",
        "retention",
        "ai",
    ]);
    return (plan?.features || []).filter((feature: any) =>
        important.has(feature.key),
    );
};
const checkoutLabel = (plan: any) => {
    if (isCurrentPlan(plan)) {
        return props.account?.access?.paid
            ? props.t("currentPlan")
            : props.t("paySelectedPlan");
    }
    return props.t("chooseAndPay");
};
</script>
<template>
    <section class="mw-stack">
        <header class="mw-page-head">
            <div>
                <p class="mw-kicker">LOOKDO APP</p>
                <h1>{{ t(section === "more" ? "more" : section) }}</h1>
            </div>
        </header>
        <p v-if="error" class="mw-error">{{ error }}</p>
        <div v-if="section === 'more'" class="mw-more-grid">
            <button
                v-for="x in moreItems"
                :key="x[0]"
                @click="emit('navigate', x[0])"
            >
                <LineIcon :name="x[1]" /><b>{{ t(x[0]) }}</b></button
            ><a :href="account.platform_url" target="_blank"
                ><LineIcon name="external" /><b>{{ t("openApp") }}</b></a
            >
        </div>
        <form
            v-if="section === 'account'"
            class="mw-panel mw-form"
            @submit.prevent="saveAccount"
        >
            <h2>{{ t("account") }}</h2>
            <p>{{ t("accountIntro") }}</p>
            <label
                >{{ t("ownerName")
                }}<input
                    v-model="accountForm.name"
                    autocomplete="name"
                    required /></label
            ><label
                >{{ t("loginEmail")
                }}<input
                    v-model="accountForm.email"
                    type="email"
                    autocomplete="email"
                    required
                /><small v-if="user.pending_email"
                    >{{ t("pendingEmail") }}: {{ user.pending_email }}</small
                ></label
            >
            <fieldset>
                <legend>{{ t("changePassword") }}</legend>
                <label
                    >{{ t("currentPassword")
                    }}<input
                        v-model="accountForm.current_password"
                        type="password"
                        autocomplete="current-password" /></label
                ><label
                    >{{ t("newPassword")
                    }}<input
                        v-model="accountForm.password"
                        type="password"
                        autocomplete="new-password"
                        minlength="10" /></label
                ><label
                    >{{ t("confirmNewPassword")
                    }}<input
                        v-model="accountForm.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        minlength="10"
                /></label>
            </fieldset>
            <p v-if="accountSaved" class="mw-notice">{{ t("accountSaved") }}</p>
            <button class="mw-primary" :disabled="busy">{{ t("save") }}</button>
        </form>
        <section v-if="section === 'account'" class="mw-panel mw-form">
            <h2>{{ t("yourData") }}</h2>
            <p>{{ t("exportIntro") }}</p>
            <button type="button" class="mw-secondary" @click="exportData">
                {{ t("exportData") }}
            </button>
            <fieldset>
                <legend>{{ t("deleteAccount") }}</legend>
                <p>{{ t("deleteAccountIntro") }}</p>
                <label
                    >{{ t("currentPassword")
                    }}<input
                        v-model="deleteForm.password"
                        type="password"
                        autocomplete="current-password"
                /></label>
                <label
                    >{{ t("typeBusinessName")
                    }}<input
                        v-model="deleteForm.confirmation"
                        :placeholder="account.tenant.name"
                /></label>
                <button
                    type="button"
                    class="mw-danger-link"
                    :disabled="busy"
                    @click="deleteOwnAccount"
                >
                    {{ t("deleteAccount") }}
                </button>
            </fieldset>
        </section>
        <form
            v-if="section === 'business'"
            class="mw-panel mw-form"
            @submit.prevent="saveProfile"
        >
            <h2>{{ t("business") }}</h2>
            <label
                >{{ t("title")
                }}<input v-model="profile.name" required /></label
            ><label
                >{{ t("appAddress")
                }}<span class="mw-inline"
                    ><input
                        v-model="profile.slug"
                        :placeholder="account.tenant.slug"
                    /><button
                        type="button"
                        class="mw-secondary"
                        :disabled="busy || !profile.slug"
                        @click="saveSlug"
                    >
                        {{ t("save") }}
                    </button></span
                ></label
            ><label
                >{{ t("contact")
                }}<input v-model="profile.contact_name" /></label
            ><label
                >{{ t("email")
                }}<input v-model="profile.email" type="email" /></label
            ><label>{{ t("phone") }}<input v-model="profile.phone" /></label
            ><label
                >{{ t("country")
                }}<select v-model="profile.country">
                    <option
                        v-for="entry in countryOptions"
                        :key="entry[0]"
                        :value="entry[0]"
                    >
                        {{ entry[1] }}
                    </option>
                </select></label
            ><label
                >{{ t("timezone")
                }}<select v-model="profile.timezone">
                    <option v-for="zone in timezoneOptions" :key="zone">
                        {{ zone }}
                    </option>
                </select></label
            ><label>{{ t("city") }}<input v-model="profile.city" /></label
            ><label>{{ t("street") }}<input v-model="profile.street" /></label
            ><label
                >{{ t("postalCode")
                }}<input v-model="profile.postal_code" /></label
            ><button class="mw-primary" :disabled="busy">
                {{ t("save") }}
            </button>
        </form>
        <form
            v-if="section === 'settings'"
            class="mw-panel mw-form"
            @submit.prevent="saveProfile"
        >
            <h2>{{ t("settings") }}</h2>
            <label
                >{{ t("languages")
                }}<select v-model="profile.locale">
                    <option
                        v-for="entry in localeOptions"
                        :key="entry[0]"
                        :value="entry[0]"
                    >
                        {{ entry[1] }}
                    </option>
                </select></label
            >
            <fieldset>
                <legend>{{ t("enabledLanguages") }}</legend>
                <label
                    v-for="entry in localeOptions"
                    :key="entry[0]"
                    class="mw-check"
                    ><input
                        v-model="profile.enabled_locales"
                        type="checkbox"
                        :value="entry[0]"
                        :disabled="profile.locale === entry[0]"
                    />{{ entry[1] }}</label
                >
            </fieldset>
            <label
                >{{ t("primaryColor")
                }}<input v-model="profile.primary_color" type="color" /></label
            ><label
                >{{ t("secondaryColor")
                }}<input v-model="profile.secondary_color" type="color"
            /></label>
            <fieldset>
                <legend>{{ t("notifications") }}</legend>
                <p>{{ t("notificationIntro") }}</p>
                <label v-if="hasPush" class="mw-check"
                    ><input
                        v-model="profile.notification_preferences.push"
                        type="checkbox"
                    />{{ t("push") }}</label
                >
                <p v-if="pushStatus" class="mw-notice">{{ pushStatus }}</p>
                <label v-if="hasSms" class="mw-check"
                    ><input
                        v-model="profile.notification_preferences.sms"
                        type="checkbox"
                    />{{ t("sms") }}</label
                ><label class="mw-check"
                    ><input
                        v-model="profile.notification_preferences.email"
                        type="checkbox"
                    />{{ t("emailChannel") }}</label
                >
            </fieldset>
            <button class="mw-primary" :disabled="busy">{{ t("save") }}</button>
        </form>
        <div v-if="section === 'team'" class="mw-two">
            <article class="mw-panel">
                <header>
                    <div>
                        <h2>{{ t("team") }}</h2>
                        <p>
                            {{ t("teamUsage") }}: {{ team.members.length }} /
                            {{ team.limit }}
                        </p>
                    </div>
                </header>
                <div
                    v-for="entry in team.members"
                    :key="entry.id"
                    class="mw-service-row"
                >
                    <template v-if="team.can_manage && entry.role !== 'owner'"
                        ><span
                            ><input v-model="entry.name" /><input
                                v-model="entry.email"
                                type="email"
                            /><select v-model="entry.role">
                                <option value="staff">{{ t("staff") }}</option>
                                <option value="manager">
                                    {{ t("manager") }}
                                </option></select
                            ><label class="mw-check"
                                ><input
                                    v-model="entry.active"
                                    type="checkbox"
                                />{{ t("activeAccess") }}</label
                            ></span
                        >
                        <div>
                            <button
                                class="mw-secondary"
                                :disabled="busy"
                                @click="updateMember(entry)"
                            >
                                {{ t("save") }}</button
                            ><button
                                class="mw-secondary"
                                :disabled="busy"
                                @click="memberSetup(entry)"
                            >
                                {{ t("newSetupLink") }}</button
                            ><button
                                class="mw-danger-link"
                                @click="removeMember(entry.id)"
                            >
                                {{ t("removeMember") }}
                            </button>
                        </div></template
                    ><span v-else
                        ><b>{{ entry.name }}</b
                        ><small
                            >{{ entry.email }} · {{ t(entry.role) }}</small
                        ></span
                    >
                </div>
            </article>
            <form
                v-if="team.can_manage"
                class="mw-panel mw-form"
                @submit.prevent="addMember"
            >
                <h2>{{ t("addMember") }}</h2>
                <label
                    >{{ t("name")
                    }}<input v-model="member.name" required /></label
                ><label
                    >{{ t("email")
                    }}<input
                        v-model="member.email"
                        type="email"
                        required /></label
                ><label
                    >{{ t("role")
                    }}<select v-model="member.role">
                        <option value="staff">{{ t("staff") }}</option>
                        <option value="manager">{{ t("manager") }}</option>
                    </select></label
                ><button
                    class="mw-primary"
                    :disabled="busy || team.members.length >= team.limit"
                >
                    {{ t("add") }}
                </button>
                <div v-if="setupUrl" class="mw-setup-link">
                    <b>{{ t("setupLink") }}</b
                    ><input :value="setupUrl" readonly /><button
                        type="button"
                        class="mw-secondary"
                        @click="copySetup"
                    >
                        {{ copied ? t("copied") : t("copyLink") }}
                    </button>
                </div>
            </form>
        </div>
        <div v-if="section === 'domain'" class="mw-panel">
            <h2>{{ t("domain") }}</h2>
            <template v-if="hasDomain"
                ><article
                    v-for="d in account.tenant.domains"
                    :key="d.id"
                    class="mw-service-row"
                >
                    <span
                        ><b>{{ d.domain }}</b
                        ><small
                            >{{ domainStatus(d)
                            }}<template v-if="d.last_error">
                                · {{ d.last_error }}</template
                            ></small
                        ></span
                    >
                    <div class="mw-domain-actions">
                        <em>{{ d.is_primary ? t("primary") : "" }}</em
                        ><button
                            v-if="
                                d.type === 'custom' && d.ssl_status !== 'active'
                            "
                            class="mw-secondary"
                            :disabled="busy"
                            @click="verifyDomain(d.id)"
                        >
                            {{ t("domainVerify") }}</button
                        ><button
                            v-if="d.type === 'custom' && d.status !== 'active'"
                            class="mw-danger-link"
                            :disabled="busy"
                            @click="removeDomain(d.id)"
                        >
                            {{ t("delete") }}
                        </button>
                    </div>
                </article>
                <p class="mw-notice">{{ t("domainDnsHint") }}</p>
                <form class="mw-inline" @submit.prevent="addDomain">
                    <input
                        v-model="domain"
                        placeholder="example.de"
                        required
                    /><button class="mw-primary" :disabled="busy">
                        {{ t("add") }}
                    </button>
                </form></template
            >
            <p v-else class="mw-warning">{{ t("domainUnavailable") }}</p>
        </div>
        <div v-if="section === 'billing'" class="mw-stack">
            <article class="mw-panel mw-billing-summary">
                <div>
                    <p class="mw-kicker">{{ t("selectedAtRegistration") }}</p>
                    <h2>{{ planName(currentSubscription?.plan) || "—" }}</h2>
                    <p>
                        {{ t(account.access.state) }} ·
                        {{ account.access.days_remaining || 0 }}
                        {{ t("days") }}
                    </p>
                </div>
                <button
                    v-if="currentSubscription?.provider_customer_id"
                    class="mw-primary"
                    :disabled="busy"
                    @click="openBillingPortal"
                >
                    {{ t("manageSubscription") }}
                </button>
                <div class="mw-billing-controls">
                    <label
                        ><span>{{ t("paymentCurrency") }}</span
                        ><select v-model="currency">
                            <option value="EUR">EUR — €</option>
                            <option value="RUB">RUB — ₽</option>
                            <option value="UAH">UAH — ₴</option>
                        </select></label
                    ><label
                        ><span>{{ t("paymentPeriod") }}</span
                        ><select v-model="cycle">
                            <option value="monthly">{{ t("monthly") }}</option>
                            <option value="yearly">{{ t("yearly") }}</option>
                        </select></label
                    >
                </div>
            </article>
            <article class="mw-panel">
                <h2>{{ t("invoiceHistory") }}</h2>
                <div
                    v-for="invoice in account.tenant.current_subscription
                        ?.invoices || []"
                    :key="invoice.id"
                    class="mw-service-row"
                >
                    <span
                        ><b>{{ invoice.invoice_number }}</b
                        ><small
                            >{{ invoice.amount_total }} {{ invoice.currency }} ·
                            {{
                                new Date(invoice.issue_date).toLocaleDateString(
                                    locale,
                                )
                            }}
                            · {{ t(`invoice_${invoice.status}`) }}</small
                        ></span
                    ><button
                        type="button"
                        class="mw-secondary mw-document-button"
                        @click="openInvoiceDocument(invoice)"
                    >
                        {{ t("openInvoice") }}
                    </button>
                </div>
                <p
                    v-if="
                        !account.tenant.current_subscription?.invoices?.length
                    "
                    class="mw-empty"
                >
                    {{ t("noInvoices") }}
                </p>
            </article>
            <article
                v-if="account.tenant.current_subscription?.payments?.length"
                class="mw-panel"
            >
                <h2>{{ t("paymentHistory") }}</h2>
                <div
                    v-for="payment in account.tenant.current_subscription
                        .payments"
                    :key="payment.id"
                    class="mw-service-row"
                >
                    <span
                        ><b>{{ payment.amount }} {{ payment.currency }}</b
                        ><small
                            >{{
                                new Date(
                                    payment.paid_at || payment.created_at,
                                ).toLocaleDateString(locale)
                            }}
                            · {{ payment.status }}</small
                        ></span
                    ><button
                        type="button"
                        class="mw-secondary mw-document-button"
                        @click="openPaymentReceipt(payment)"
                    >
                        {{ t("openReceipt") }}
                    </button>
                </div>
            </article>
            <header class="mw-plan-choice-head">
                <div>
                    <p class="mw-kicker">{{ t("choosePlan") }}</p>
                    <h2>{{ t("choosePlanTitle") }}</h2>
                    <p>{{ t("choosePlanIntro") }}</p>
                </div>
            </header>
            <div class="mw-plan-grid">
                <article
                    v-for="plan in plans"
                    :key="plan.id"
                    :class="{
                        'is-current': isCurrentPlan(plan),
                        'is-recommended': plan.badge,
                    }"
                >
                    <img v-if="plan.image_url" :src="plan.image_url" />
                    <div class="mw-plan-badges">
                        <span v-if="isCurrentPlan(plan)" class="current">{{
                            t("yourSelection")
                        }}</span>
                        <span v-if="plan.badge">{{ plan.badge }}</span>
                    </div>
                    <h2>{{ planName(plan) }}</h2>
                    <p class="mw-plan-description">
                        {{ planDescription(plan) }}
                    </p>
                    <div class="mw-plan-price">
                        <strong>{{ formatPlanPrice(plan) }}</strong>
                        <span>{{
                            t(cycle === "yearly" ? "perYear" : "perMonth")
                        }}</span>
                    </div>
                    <ul class="mw-plan-features">
                        <li
                            v-for="feature in planFeatures(plan)"
                            :key="feature.key"
                            :class="{ disabled: !feature.included }"
                        >
                            <span>{{ feature.included ? "✓" : "—" }}</span
                            >{{ feature.label }}
                        </li>
                    </ul>
                    <button
                        class="mw-primary"
                        :disabled="
                            busy ||
                            planPrice(plan) === null ||
                            (isCurrentPlan(plan) && account.access.paid)
                        "
                        @click="checkout(plan.id)"
                    >
                        {{ checkoutLabel(plan) }}
                    </button>
                </article>
            </div>
        </div>
    </section>
</template>

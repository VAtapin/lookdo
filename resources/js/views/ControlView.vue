<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api } from "../api";
import AdminModal from "../components/admin/AdminModal.vue";
import AuditDetailsModal from "../components/admin/AuditDetailsModal.vue";
import AdminPagination from "../components/admin/AdminPagination.vue";
import AdminSettings from "../components/admin/AdminSettings.vue";
import TemplateEditor from "../components/admin/TemplateEditor.vue";
import RegistryToolbar from "../components/admin/RegistryToolbar.vue";
import RichContentEditor from "../components/admin/RichContentEditor.vue";

const route = useRoute();
const router = useRouter();
const section = computed(() =>
    route.path.startsWith("/control/settings")
        ? "settings"
        : String(route.params.section || "dashboard"),
);
const data = ref<any>(null);
const me = ref<any>(null);
const error = ref("");
const noticeText = ref("");
const busy = ref(false);
const translating = ref(false);
const modal = ref("");
const selectedTenant = ref<any>(null);
const selectedPage = ref<any>(null);
const selectedTemplate = ref<any>(null);
const selectedAudit = ref<any>(null);
const confirmAction = ref<any>(null);
const pageTranslation = reactive<{
    phase: "idle" | "running" | "ready" | "error";
    pageId: number | null;
    pageKey: string;
    message: string;
}>({ phase: "idle", pageId: null, pageKey: "", message: "" });
const pageTranslationDrafts = reactive<Record<number, any>>({});
const contentLocale = ref("de");
const planLocale = ref("de");
const catalogKind = ref("template");
const editingPlanId = ref<number | null>(null);
const lookups = ref<any>({ plans: [], variations: [], categories: [] });
const entitlementCatalog = ref<any>({ groups: {}, definitions: {} });
const planLocales = [
    ["de", "Deutsch"],
    ["en", "English"],
    ["ru", "Русский"],
    ["uk", "Українська"],
];

const nav = [
    ["dashboard", "▦", "Übersicht"],
    ["tenants", "◎", "Kunden"],
    ["administrators", "♜", "Administratoren"],
    ["subscriptions", "◉", "Abrechnung"],
    ["plans", "€", "Tarife"],
    ["stripe", "S", "Stripe"],
    ["sms", "✉", "SMS-Protokoll"],
    ["templates", "≡", "Vorlagen"],
    ["ai", "✦", "KI-Wörterbuch"],
    ["classifications", "↯", "KI-Entscheidungen"],
    ["content", "□", "Inhalte"],
    ["settings", "⚙", "Einstellungen"],
    ["backups", "↥", "Backups"],
    ["audit", "⌁", "Prüfprotokoll"],
];
const endpoint: Record<string, string> = {
    dashboard: "/control/dashboard",
    tenants: "/control/tenants",
    administrators: "/control/administrators",
    subscriptions: "/control/subscriptions",
    plans: "/control/plans",
    stripe: "/control/stripe",
    sms: "/control/sms",
    templates: "/control/taxonomy",
    ai: "/control/phrases",
    classifications: "/control/classifications",
    content: "/control/settings",
    settings: "/control/settings",
    backups: "/control/backups",
    audit: "/control/audits",
};
const serverSections = new Set([
    "tenants",
    "administrators",
    "subscriptions",
    "ai",
    "classifications",
    "sms",
    "audit",
]);
const addLabels: Record<string, string> = {
    tenants: "Kunde",
    plans: "Tarif",
    templates: "Eintrag",
    ai: "Begriff",
};
const metricLabels: Record<string, string> = {
    tenants: "Kunden",
    active_tenants: "Technisch aktive Konten",
    trialing: "Testphase",
    paid: "Bezahlt",
    complimentary: "Kostenlos",
    domains_attention: "Domains prüfen",
    classifications_30d: "Klassifizierungen (30 Tage)",
    ai_spend_month: "KI-Kosten im Monat",
    mrr: "Monatlicher Umsatz",
};
const smsEventLabels: Record<string, string> = {
    request_received: "Anfrage erhalten",
    master_replied: "Meister hat geantwortet",
    work_ready: "Arbeit fertig",
    agreement_reminder: "Vereinbarung erinnern",
};
const smsStatusLabels: Record<string, string> = {
    queued: "Warteschlange",
    sending: "Wird gesendet",
    accepted: "Angenommen",
    delivered: "Zugestellt",
    failed: "Fehlgeschlagen",
};
function tenantAccessLabel(tenant: any): string {
    if (tenant?.manual_access_active)
        return `Manuell freigeschaltet · noch ${Number(tenant.manual_access_days_remaining || 0)} Tage`;
    const subscription = tenant?.current_subscription;
    const days = Number(subscription?.access_days_remaining || 0);
    switch (subscription?.access_state) {
        case "trialing":
            return `Testphase · noch ${days} Tage`;
        case "complimentary":
            return subscription.access_expires_at
                ? `Manuell freigeschaltet · noch ${days} Tage`
                : "Manuell freigeschaltet";
        case "paid":
            return "Bezahlt";
        case "expired":
            return "Zugang abgelaufen";
        case "past_due":
            return "Zahlung überfällig";
        case "canceled":
            return "Gekündigt";
        default:
            return "Nicht bezahlt";
    }
}
function tenantAccessClass(tenant: any): string {
    return tenant?.manual_access_active
        ? "complimentary"
        : tenant?.current_subscription?.access_state || "unpaid";
}
function subscriptionAccessLabel(subscription: any): string {
    return tenantAccessLabel({ current_subscription: subscription });
}
function subscriptionAccessClass(subscription: any): string {
    return subscription?.access_state || "unpaid";
}
const sortOptions: Record<string, Array<[string, string]>> = {
    tenants: [
        ["created_at", "Erstellt"],
        ["name", "Name"],
        ["status", "Status"],
        ["last_activity_at", "Letzte Aktivität"],
    ],
    administrators: [
        ["created_at", "Erstellt"],
        ["name", "Name"],
        ["email", "E-Mail"],
        ["last_login_at", "Letzte Anmeldung"],
    ],
    subscriptions: [
        ["created_at", "Erstellt"],
        ["status", "Status"],
        ["provider", "Anbieter"],
        ["current_period_end", "Periodenende"],
    ],
    plans: [
        ["sort_order", "Reihenfolge"],
        ["code", "Code"],
        ["price_monthly", "Preis"],
    ],
    templates: [
        ["kind", "Typ"],
        ["code", "Code"],
        ["sort_order", "Reihenfolge"],
        ["enabled", "Status"],
    ],
    ai: [
        ["created_at", "Erstellt"],
        ["phrase", "Begriff"],
        ["locale", "Sprache"],
        ["weight", "Gewichtung"],
    ],
    classifications: [
        ["created_at", "Datum"],
        ["confidence", "Sicherheit"],
        ["source", "Quelle"],
    ],
    content: [
        ["label", "Name"],
        ["key", "URL"],
    ],
    settings: [],
    sms: [
        ["created_at", "Datum"],
        ["status", "Status"],
        ["event_type", "Ereignis"],
        ["cost", "Kosten"],
    ],
    backups: [
        ["created_at", "Erstellt"],
        ["name", "Name"],
    ],
    audit: [
        ["created_at", "Datum"],
        ["action", "Aktion"],
        ["actor_id", "Benutzer"],
    ],
};
const statusOptions: Record<string, Array<[string, string]>> = {
    tenants: [
        ["active", "Aktiv"],
        ["suspended", "Gesperrt"],
        ["archived", "Archiviert"],
    ],
    administrators: [
        ["active", "Aktiv"],
        ["inactive", "Gesperrt"],
    ],
    subscriptions: [
        ["active", "Aktiv"],
        ["trialing", "Testphase"],
        ["incomplete", "Unvollständig"],
        ["past_due", "Überfällig"],
        ["canceled", "Gekündigt"],
    ],
    plans: [
        ["active", "Aktiv"],
        ["inactive", "Archiviert"],
    ],
    templates: [
        ["active", "Aktiv"],
        ["inactive", "Inaktiv"],
    ],
    sms: [
        ["queued", "Warteschlange"],
        ["sending", "Wird gesendet"],
        ["accepted", "Angenommen"],
        ["delivered", "Zugestellt"],
        ["failed", "Fehlgeschlagen"],
    ],
    ai: [
        ["active", "Aktiv"],
        ["inactive", "Inaktiv"],
    ],
    content: [
        ["active", "Veröffentlicht"],
        ["inactive", "Entwurf"],
    ],
};
const filters = reactive({
    search: "",
    status: "",
    secondary: "",
    sort: "created_at",
    direction: "desc",
    per_page: 25,
    page: 1,
});
const tenantForm = reactive<any>({
    name: "",
    slug: "",
    owner_name: "",
    owner_email: "",
    owner_password: "",
    country: "DE",
    locale: "ru",
    business_description: "",
    variation_id: null,
    plan_id: null,
    complimentary: false,
    complimentary_days: 14,
});
const customTenantDomain = ref("");
const manualAccessDays = ref(14);
const planForm = reactive<any>({
    code: "",
    name: { de: "", en: "", ru: "", uk: "" },
    description: { de: "", en: "", ru: "", uk: "" },
    price_monthly: 19,
    price_yearly: 190,
    currency: "EUR",
    prices: {
        EUR: { monthly: 19, yearly: 190 },
        RUB: { monthly: 1990, yearly: 19900 },
        UAH: { monthly: 890, yearly: 8900 },
    },
    trial_days: 0,
    is_active: true,
    is_public: true,
    sort_order: 50,
    badge_text: { de: "", en: "", ru: "", uk: "" },
    entitlements: {},
});
const planImageFile = ref<File | null>(null);
const planImagePreview = ref("");
const planExistingImage = ref("");
const phraseForm = reactive<any>({
    category_id: null,
    variation_id: null,
    locale: "ru",
    phrase: "",
    weight: 1,
    enabled: true,
});
const categoryForm = reactive<any>({
    code: "",
    name: { ru: "", de: "", en: "", uk: "" },
    enabled: true,
    sort_order: 50,
});
const variationForm = reactive<any>({
    category_id: null,
    code: "",
    name: { ru: "", de: "", en: "", uk: "" },
    template_code: "",
    enabled: true,
    priority: 50,
});
const templateForm = reactive<any>({
    category_id: null,
    variation_id: null,
    code: "",
    parent_code: "",
    name: { ru: "", de: "", en: "", uk: "" },
    configuration:
        '{\n  "media": {"photos_min": 1, "photos_max": 5, "video_allowed": true},\n  "fields": []\n}',
    enabled: true,
    version: 1,
    sort_order: 50,
});
const overrideForm = reactive({ key: "", value: "" });
function toast(message: string) {
    noticeText.value = message;
    window.setTimeout(() => (noticeText.value = ""), 3500);
}
function queryString() {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(
        ([key, value]) => value !== "" && params.set(key, String(value)),
    );
    const secondaryKeys: Record<string, string> = {
        subscriptions: "provider",
        ai: "locale",
        classifications: "source",
    };
    if (secondaryKeys[section.value] && filters.secondary)
        params.set(secondaryKeys[section.value], filters.secondary);
    return params.toString();
}
async function load() {
    error.value = "";
    try {
        me.value ||= await api("/me");
        if (!me.value.user?.is_super_admin) return router.push("/login");
        const url = endpoint[section.value] || endpoint.dashboard;
        if (section.value === "plans") {
            const [plans, catalog] = await Promise.all([
                api(url),
                api("/control/plan-entitlements"),
            ]);
            data.value = plans;
            entitlementCatalog.value = catalog;
        } else
            data.value = await api(
                serverSections.has(section.value)
                    ? `${url}?${queryString()}`
                    : url,
            );
        if (["tenants", "ai", "templates"].includes(section.value)) {
            const [plans, taxonomy] = await Promise.all([
                api<any[]>("/control/plans"),
                api<any>("/control/taxonomy"),
            ]);
            lookups.value = {
                plans,
                categories: taxonomy.categories,
                variations: taxonomy.categories.flatMap(
                    (category: any) => category.variations,
                ),
            };
        }
    } catch (exception: any) {
        error.value = exception.message;
        if (String(exception.message).includes("Unauthenticated"))
            router.push("/login");
    }
}
function resetFilters() {
    Object.assign(filters, {
        search: "",
        status: "",
        secondary: "",
        sort: sortOptions[section.value]?.[0]?.[0] || "created_at",
        direction: "desc",
        per_page: 25,
        page: 1,
    });
}
let debounce: number | undefined;
watch(section, () => {
    selectedTenant.value = null;
    selectedTemplate.value = null;
    modal.value = "";
    resetFilters();
    data.value = null;
    load();
});
watch(
    filters,
    () => {
        if (!serverSections.has(section.value)) return;
        window.clearTimeout(debounce);
        debounce = window.setTimeout(load, 280);
    },
    { deep: true },
);
onMounted(load);

const catalogRows = computed(() => {
    if (section.value === "plans")
        return (data.value || []).map((item: any) => ({
            ...item,
            label: item.name?.de || item.code,
        }));
    if (section.value === "templates") {
        const categories = (data.value?.categories || []).map((item: any) => ({
            ...item,
            kind: "category",
            label: item.name?.de || item.code,
        }));
        const variations = (data.value?.categories || []).flatMap(
            (category: any) =>
                category.variations.map((item: any) => ({
                    ...item,
                    kind: "variation",
                    categoryLabel: category.name?.de || category.code,
                    label: item.name?.de || item.code,
                    sort_order: item.priority,
                })),
        );
        const templates = (data.value?.templates || []).map((item: any) => ({
            ...item,
            kind: "template",
            label: item.name?.de || item.code,
        }));
        return [...categories, ...variations, ...templates];
    }
    if (section.value === "content")
        return (data.value?.pages || []).map((item: any) => ({
            ...item,
            kind: "page",
            label: item.title?.de || item.key,
        }));
    if (section.value === "backups") return data.value?.backups || [];
    return [];
});
const localFiltered = computed(() => {
    let result = [...catalogRows.value];
    const term = filters.search.toLowerCase().trim();
    if (term)
        result = result.filter((row: any) =>
            JSON.stringify(row).toLowerCase().includes(term),
        );
    if (filters.status)
        result = result.filter((row: any) =>
            filters.status === "active"
                ? (row.enabled ?? row.is_active ?? row.is_published)
                : !(row.enabled ?? row.is_active ?? row.is_published),
        );
    if (filters.secondary)
        result = result.filter((row: any) => row.kind === filters.secondary);
    const key = filters.sort;
    result.sort((a: any, b: any) =>
        String(a[key] ?? "").localeCompare(String(b[key] ?? ""), "de", {
            numeric: true,
        }),
    );
    if (filters.direction === "desc") result.reverse();
    return result;
});
const localPageRows = computed(() =>
    localFiltered.value.slice(
        (filters.page - 1) * filters.per_page,
        filters.page * filters.per_page,
    ),
);
const rows = computed(() =>
    serverSections.has(section.value)
        ? data.value?.data || []
        : localPageRows.value,
);
const pager = computed(() =>
    serverSections.has(section.value)
        ? {
              current: data.value?.current_page || 1,
              last: data.value?.last_page || 1,
              from: data.value?.from,
              to: data.value?.to,
              total: data.value?.total || 0,
          }
        : {
              current: filters.page,
              last: Math.max(
                  1,
                  Math.ceil(localFiltered.value.length / filters.per_page),
              ),
              from: localFiltered.value.length
                  ? (filters.page - 1) * filters.per_page + 1
                  : 0,
              to: Math.min(
                  filters.page * filters.per_page,
                  localFiltered.value.length,
              ),
              total: localFiltered.value.length,
          },
);
const entitlementGroups = computed(() =>
    Object.entries(entitlementCatalog.value.groups || {}).map(
        ([key, label]) => ({
            key,
            label,
            items: Object.entries(entitlementCatalog.value.definitions || {})
                .filter(([, definition]: any) => definition.group === key)
                .map(([itemKey, definition]) => ({
                    key: itemKey,
                    ...(definition as any),
                })),
        }),
    ),
);
function changePage(page: number) {
    filters.page = Math.max(1, Math.min(page, pager.value.last));
}
function defaultEntitlements() {
    return Object.fromEntries(
        Object.entries(entitlementCatalog.value.definitions || {}).map(
            ([key, definition]: any) => [key, String(definition.default ?? 0)],
        ),
    );
}
function resetPlanForm() {
    Object.assign(planForm, {
        code: "",
        name: { de: "", en: "", ru: "", uk: "" },
        description: { de: "", en: "", ru: "", uk: "" },
        badge_text: { de: "", en: "", ru: "", uk: "" },
        price_monthly: 19,
        price_yearly: 190,
        currency: "EUR",
        prices: {
            EUR: { monthly: 19, yearly: 190 },
            RUB: { monthly: 1990, yearly: 19900 },
            UAH: { monthly: 890, yearly: 8900 },
        },
        trial_days: 0,
        is_active: true,
        is_public: true,
        sort_order: 50,
        entitlements: defaultEntitlements(),
    });
    planImageFile.value = null;
    planImagePreview.value = "";
    planExistingImage.value = "";
    planLocale.value = "de";
}
function openAdd() {
    if (section.value === "tenants") modal.value = "tenant";
    if (section.value === "plans") {
        editingPlanId.value = null;
        resetPlanForm();
        modal.value = "plan";
    }
    if (section.value === "templates") modal.value = "catalog";
    if (section.value === "ai") modal.value = "phrase";
}
async function submit(action: () => Promise<any>, success: string) {
    busy.value = true;
    error.value = "";
    try {
        await action();
        modal.value = "";
        toast(success);
        await load();
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
async function createTenant() {
    await submit(
        () =>
            api("/control/tenants", {
                method: "POST",
                body: JSON.stringify(tenantForm),
            }),
        "Kunde wurde angelegt.",
    );
}
function selectPlanImage(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    planImageFile.value = file;
    planImagePreview.value = URL.createObjectURL(file);
    input.value = "";
}
function clearPendingPlanImage() {
    planImageFile.value = null;
    planImagePreview.value = planExistingImage.value;
}
async function deletePlanImage() {
    if (!editingPlanId.value) return;
    busy.value = true;
    error.value = "";
    try {
        await api(`/control/plans/${editingPlanId.value}/image`, {
            method: "DELETE",
        });
        planImageFile.value = null;
        planImagePreview.value = "";
        planExistingImage.value = "";
        toast("Tarifbild wurde entfernt.");
        await load();
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
async function savePlan() {
    planForm.currency = "EUR";
    planForm.price_monthly = planForm.prices.EUR.monthly;
    planForm.price_yearly = planForm.prices.EUR.yearly;
    busy.value = true;
    error.value = "";
    try {
        const saved = await api<any>(
            editingPlanId.value
                ? `/control/plans/${editingPlanId.value}`
                : "/control/plans",
            {
                method: editingPlanId.value ? "PUT" : "POST",
                body: JSON.stringify(planForm),
            },
        );
        editingPlanId.value = saved.id;
        if (planImageFile.value) {
            const body = new FormData();
            body.append("image", planImageFile.value);
            await api(`/control/plans/${saved.id}/image`, {
                method: "POST",
                body,
            });
        }
        modal.value = "";
        planImageFile.value = null;
        planImagePreview.value = "";
        toast("Tarif wurde gespeichert.");
        await load();
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
function editPlan(plan: any) {
    editingPlanId.value = plan.id;
    planImageFile.value = null;
    planImagePreview.value = plan.image_url || "";
    planExistingImage.value = plan.image_url || "";
    Object.assign(planForm, {
        ...plan,
        name: { de: "", en: "", ru: "", uk: "", ...plan.name },
        description: {
            de: "",
            en: "",
            ru: "",
            uk: "",
            ...(plan.description || {}),
        },
        badge_text: {
            de: "",
            en: "",
            ru: "",
            uk: "",
            ...(plan.badge_text || {}),
        },
        prices: {
            EUR: {
                monthly: Number(
                    plan.prices?.EUR?.monthly ?? plan.price_monthly,
                ),
                yearly: Number(plan.prices?.EUR?.yearly ?? plan.price_yearly),
            },
            RUB: {
                monthly: Number(plan.prices?.RUB?.monthly ?? 0),
                yearly: Number(plan.prices?.RUB?.yearly ?? 0),
            },
            UAH: {
                monthly: Number(plan.prices?.UAH?.monthly ?? 0),
                yearly: Number(plan.prices?.UAH?.yearly ?? 0),
            },
        },
        entitlements: {
            ...defaultEntitlements(),
            ...Object.fromEntries(
                plan.entitlements.map((item: any) => [item.key, item.value]),
            ),
        },
    });
    planLocale.value = "de";
    modal.value = "plan";
}
async function translatePlan() {
    const source = planLocale.value;
    if (!planForm.name[source]?.trim()) {
        error.value =
            "Bitte zuerst den Tarifnamen in der gewählten Ausgangssprache eingeben.";
        return;
    }
    translating.value = true;
    error.value = "";
    try {
        const translated = await api<any>("/control/plans/translate", {
            method: "POST",
            body: JSON.stringify({
                source_locale: source,
                name: planForm.name[source],
                description: planForm.description[source] || "",
                badge_text: planForm.badge_text[source] || "",
            }),
        });
        Object.assign(planForm.name, translated.name);
        Object.assign(planForm.description, translated.description);
        Object.assign(planForm.badge_text, translated.badge_text);
        toast(
            "KI-Übersetzungen wurden eingefügt. Bitte vor dem Speichern prüfen.",
        );
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        translating.value = false;
    }
}
function activeEntitlements(plan: any) {
    return (
        plan.entitlements?.filter(
            (item: any) =>
                entitlementCatalog.value.definitions?.[item.key]?.type ===
                    "boolean" && String(item.value) === "1",
        ).length || 0
    );
}
async function saveCatalog() {
    if (catalogKind.value === "category")
        return submit(
            () =>
                api("/control/categories", {
                    method: "POST",
                    body: JSON.stringify(categoryForm),
                }),
            "Kategorie wurde angelegt.",
        );
    if (catalogKind.value === "variation")
        return submit(
            () =>
                api("/control/variations", {
                    method: "POST",
                    body: JSON.stringify(variationForm),
                }),
            "Variante wurde angelegt.",
        );
    busy.value = true;
    error.value = "";
    try {
        const payload = {
            ...templateForm,
            configuration: JSON.parse(templateForm.configuration),
        };
        const created = await api<any>("/control/templates", {
            method: "POST",
            body: JSON.stringify(payload),
        });
        modal.value = "";
        await load();
        selectedTemplate.value = {
            ...created,
            kind: "template",
            label: created.name?.de || created.code,
        };
        toast("Vorlage wurde angelegt. Bitte jetzt vollständig konfigurieren.");
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
function choosePhraseVariation() {
    const variation = lookups.value.variations.find(
        (item: any) => item.id === phraseForm.variation_id,
    );
    phraseForm.category_id = variation?.category_id || null;
}
async function savePhrase() {
    choosePhraseVariation();
    await submit(
        () =>
            api("/control/phrases", {
                method: "POST",
                body: JSON.stringify(phraseForm),
            }),
        "Begriff wurde hinzugefügt.",
    );
}
async function togglePhrase(item: any) {
    await api(`/control/phrases/${item.id}`, {
        method: "PUT",
        body: JSON.stringify({
            ...item,
            enabled: !item.enabled,
            category_id: item.category_id,
            variation_id: item.variation_id,
        }),
    });
    await load();
}
async function toggleCatalog(item: any) {
    const path =
        item.kind === "category"
            ? `categories/${item.id}`
            : item.kind === "variation"
              ? `variations/${item.id}`
              : `templates/${item.id}/toggle`;
    const body =
        item.kind === "template"
            ? { enabled: !item.enabled }
            : { ...item, enabled: !item.enabled };
    await api(`/control/${path}`, {
        method: "PUT",
        body: JSON.stringify(body),
    });
    await load();
}
function editTemplate(item: any) {
    if (item.kind === "template")
        selectedTemplate.value = JSON.parse(JSON.stringify(item));
}
async function templateSaved() {
    selectedTemplate.value = null;
    toast("Vorlage wurde gespeichert.");
    await load();
}
function templateError(message: string) {
    error.value = message;
}
async function openTenant(item: any) {
    try {
        selectedTenant.value = await api(`/control/tenants/${item.id}`);
        customTenantDomain.value = "";
        manualAccessDays.value = 14;
    } catch (exception: any) {
        error.value = exception.message;
        selectedTenant.value = null;
    }
}
async function refreshSelectedTenant() {
    if (selectedTenant.value?.id)
        selectedTenant.value = await api(
            `/control/tenants/${selectedTenant.value.id}`,
        );
}
async function updateTenant(payload: any) {
    busy.value = true;
    try {
        await api(`/control/tenants/${selectedTenant.value.id}`, {
            method: "PUT",
            body: JSON.stringify(payload),
        });
        selectedTenant.value = await api(
            `/control/tenants/${selectedTenant.value.id}`,
        );
        await load();
        toast("Kunde wurde aktualisiert.");
    } finally {
        busy.value = false;
    }
}
async function saveTenantDetails() {
    const owner = selectedTenant.value.users?.[0];
    await updateTenant({
        name: selectedTenant.value.name,
        owner_name: owner?.name,
        owner_email: owner?.email,
    });
}
async function grantTenantAccess() {
    busy.value = true;
    error.value = "";
    try {
        await api(`/control/tenants/${selectedTenant.value.id}/grant-access`, {
            method: "POST",
            body: JSON.stringify({ days: manualAccessDays.value }),
        });
        await refreshSelectedTenant();
        await load();
        toast(
            `Kunde wurde für ${manualAccessDays.value} Tage ohne Zahlung freigeschaltet.`,
        );
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
async function saveOverride() {
    await api(`/control/tenants/${selectedTenant.value.id}/entitlement`, {
        method: "PUT",
        body: JSON.stringify(overrideForm),
    });
    toast("Leistung wurde gespeichert.");
}
async function impersonate() {
    await api(`/control/tenants/${selectedTenant.value.id}/impersonate`, {
        method: "POST",
    });
    location.href = "/app";
}
function askConfirmation(config: any) {
    confirmAction.value = config;
    modal.value = "confirm";
}
async function executeConfirmed() {
    if (!confirmAction.value) return;
    busy.value = true;
    error.value = "";
    try {
        await confirmAction.value.run();
        modal.value = "";
        confirmAction.value = null;
        await refreshSelectedTenant();
        await load();
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
function toggleUser(user: any) {
    askConfirmation({
        title: user.is_active ? "Inhaber sperren?" : "Inhaber aktivieren?",
        message: user.is_active
            ? `${user.name} kann sich danach nicht mehr anmelden.`
            : `${user.name} erhält wieder Zugriff auf das Kundenkonto.`,
        confirmLabel: user.is_active ? "Inhaber sperren" : "Inhaber aktivieren",
        danger: user.is_active,
        run: async () => {
            await api(`/control/tenants/${selectedTenant.value.id}/owner`, {
                method: "PUT",
                body: JSON.stringify({ is_active: !user.is_active }),
            });
            toast(
                user.is_active
                    ? "Inhaber wurde gesperrt."
                    : "Inhaber wurde aktiviert.",
            );
        },
    });
}
function resetPassword(user: any) {
    askConfirmation({
        title: "Zugangslink versenden?",
        message: `Ein Link zum Festlegen eines neuen Passworts wird an ${user.email} gesendet.`,
        confirmLabel: "Link versenden",
        run: async () => {
            await api(
                `/control/tenants/${selectedTenant.value.id}/owner/password-reset`,
                { method: "POST" },
            );
            toast("Link zum Zurücksetzen wurde versendet.");
        },
    });
}
async function domainAction(domain: any, action: string) {
    await api(
        `/control/tenants/${selectedTenant.value.id}/domains/${domain.id}/${action}`,
        { method: "POST" },
    );
    await refreshSelectedTenant();
    await load();
}
async function deleteDomain(domain: any) {
    if (!confirm(`${domain.domain} wirklich löschen?`)) return;
    await api(
        `/control/tenants/${selectedTenant.value.id}/domains/${domain.id}`,
        { method: "DELETE" },
    );
    await refreshSelectedTenant();
    await load();
}
function deleteTenantPermanently() {
    const tenant = {
        id: selectedTenant.value.id,
        name: selectedTenant.value.name,
    };
    askConfirmation({
        title: "Kunden endgültig löschen",
        message: `${tenant.name} sowie alle lokalen Daten, Anfragen, Dateien und der nur diesem Kunden gehörende Inhaber werden dauerhaft gelöscht. Ein aktives Stripe-Abonnement muss vorher beendet sein.`,
        confirmLabel: "Endgültig löschen",
        danger: true,
        run: async () => {
            await api(`/control/tenants/${tenant.id}`, {
                method: "DELETE",
                body: JSON.stringify({ confirmed: true }),
            });
            selectedTenant.value = null;
            await load();
            toast("Kunde wurde endgültig gelöscht.");
        },
    });
}
async function syncPlan(plan: any) {
    await submit(
        () => api(`/control/plans/${plan.id}/stripe-sync`, { method: "POST" }),
        "Tarif wurde mit Stripe synchronisiert.",
    );
}
async function addTenantDomain() {
    if (!customTenantDomain.value.trim()) return;
    busy.value = true;
    error.value = "";
    try {
        await api(`/tenant/${selectedTenant.value.id}/domains`, {
            method: "POST",
            body: JSON.stringify({ domain: customTenantDomain.value }),
        });
        customTenantDomain.value = "";
        await refreshSelectedTenant();
        await load();
        toast("Domain wurde hinzugefügt.");
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
async function syncAllPlans() {
    await submit(
        () => api("/control/stripe/sync-plans", { method: "POST" }),
        "Alle Tarife wurden synchronisiert.",
    );
}
async function backupAction(action: string, name?: string) {
    if (action === "delete" && !confirm(`${name} wirklich löschen?`)) return;
    await submit(
        () =>
            api(
                name
                    ? `/control/backups/${name}${action === "verify" ? "/verify" : ""}`
                    : "/control/backups",
                { method: action === "delete" ? "DELETE" : "POST" },
            ),
        action === "create"
            ? "Backup wurde erstellt."
            : action === "verify"
              ? "Backup ist vollständig."
              : "Backup wurde gelöscht.",
    );
}
function applyPageTranslation(page: any, translated: any) {
    page.title = {
        de: "",
        en: "",
        ru: "",
        uk: "",
        ...(page.title || {}),
        ...(translated.title || {}),
    };
    page.content = {
        de: "",
        en: "",
        ru: "",
        uk: "",
        ...(page.content || {}),
        ...(translated.content || {}),
    };
}
function editPage(page: any) {
    selectedPage.value = JSON.parse(JSON.stringify(page));
    if (pageTranslationDrafts[page.id])
        applyPageTranslation(
            selectedPage.value,
            pageTranslationDrafts[page.id],
        );
    contentLocale.value = "de";
    modal.value = "page";
}
function closePageEditor() {
    modal.value = "";
}
async function openPageTranslationResult() {
    if (!pageTranslation.pageId || pageTranslation.phase !== "ready") return;
    if (section.value !== "content") await router.push("/control/content");
    if (!data.value?.pages) data.value = await api("/control/settings");
    const page = data.value.pages.find(
        (item: any) => Number(item.id) === pageTranslation.pageId,
    );
    if (page) editPage(page);
}
async function savePage() {
    busy.value = true;
    error.value = "";
    try {
        const pageId = Number(selectedPage.value.id);
        await api(`/control/pages/${pageId}`, {
            method: "PUT",
            body: JSON.stringify({
                title: selectedPage.value.title,
                content: selectedPage.value.content,
                is_published: selectedPage.value.is_published,
            }),
        });
        delete pageTranslationDrafts[pageId];
        if (pageTranslation.pageId === pageId)
            Object.assign(pageTranslation, {
                phase: "idle",
                pageId: null,
                pageKey: "",
                message: "",
            });
        modal.value = "";
        toast("Inhalt wurde veröffentlicht.");
        await load();
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
async function translatePage() {
    const source = contentLocale.value;
    if (!selectedPage.value?.title?.[source]?.trim()) {
        error.value =
            "Bitte zuerst den Titel in der gewählten Ausgangssprache eingeben.";
        return;
    }
    const pageId = Number(selectedPage.value.id);
    const pageKey = String(selectedPage.value.key);
    const payload = {
        source_locale: source,
        title: selectedPage.value.title[source],
        content: selectedPage.value.content[source] || "",
    };
    translating.value = true;
    error.value = "";
    Object.assign(pageTranslation, {
        phase: "running",
        pageId,
        pageKey,
        message: "",
    });
    try {
        const translated = await api<any>("/control/pages/translate", {
            method: "POST",
            body: JSON.stringify(payload),
        });
        pageTranslationDrafts[pageId] = translated;
        if (selectedPage.value?.id === pageId)
            applyPageTranslation(selectedPage.value, translated);
        Object.assign(pageTranslation, {
            phase: "ready",
            message:
                "Die Übersetzung ist fertig. Bitte öffnen, prüfen und speichern.",
        });
        toast("KI-Übersetzung ist fertig. Öffnen, prüfen und speichern.");
    } catch (exception: any) {
        error.value = exception.message;
        Object.assign(pageTranslation, {
            phase: "error",
            message: exception.message,
        });
    } finally {
        translating.value = false;
    }
}
async function logout() {
    await api("/logout", { method: "POST" });
    router.push("/login");
}
function formatDate(value: string | null) {
    return value
        ? new Intl.DateTimeFormat("de-DE", {
              dateStyle: "medium",
              timeStyle: "short",
          }).format(new Date(value))
        : "—";
}
</script>

<template>
    <div class="control-shell">
        <aside class="control-sidebar">
            <div class="control-wordmark">
                <img
                    decoding="async"
                    :src="'/brand/lookdo-mark.webp'"
                    alt="LOOKDO"
                /><small>SUPER ADMIN</small>
            </div>
            <nav>
                <RouterLink
                    v-for="item in nav"
                    :key="item[0]"
                    :to="`/control/${item[0]}`"
                    :class="{ active: section === item[0] }"
                    ><span>{{ item[1] }}</span
                    >{{ item[2] }}</RouterLink
                >
            </nav>
            <button class="sidebar-logout" @click="logout">Abmelden</button>
        </aside>
        <main class="control-main">
            <header class="control-header">
                <div>
                    <p class="eyebrow">LOOKDO VERWALTUNG</p>
                    <h1>{{ nav.find((item) => item[0] === section)?.[2] }}</h1>
                </div>
                <div class="admin-chip">
                    <span>SA</span>
                    <div>
                        <b>{{ me?.user?.name }}</b
                        ><small>{{ me?.user?.email }}</small>
                    </div>
                </div>
            </header>
            <aside
                v-if="pageTranslation.phase !== 'idle'"
                class="translation-job"
                :class="pageTranslation.phase"
                role="status"
                aria-live="polite"
            >
                <span class="translation-job-icon">{{
                    pageTranslation.phase === "ready"
                        ? "✓"
                        : pageTranslation.phase === "error"
                          ? "!"
                          : "✦"
                }}</span>
                <div>
                    <b>{{
                        pageTranslation.phase === "running"
                            ? `KI übersetzt /${pageTranslation.pageKey}`
                            : pageTranslation.phase === "ready"
                              ? `KI-Übersetzung für /${pageTranslation.pageKey} ist fertig`
                              : `KI-Übersetzung für /${pageTranslation.pageKey} ist fehlgeschlagen`
                    }}</b
                    ><small v-if="pageTranslation.phase === 'running'"
                        >Das Bearbeitungsfenster darf geschlossen werden. Bitte
                        diesen Browser-Tab geöffnet lassen.</small
                    ><small v-else>{{ pageTranslation.message }}</small>
                </div>
                <button
                    v-if="pageTranslation.phase === 'ready'"
                    type="button"
                    @click="openPageTranslationResult"
                >
                    Ergebnis öffnen</button
                ><button
                    v-else-if="pageTranslation.phase === 'error'"
                    type="button"
                    @click="
                        Object.assign(pageTranslation, {
                            phase: 'idle',
                            pageId: null,
                            pageKey: '',
                            message: '',
                        })
                    "
                >
                    Ausblenden
                </button>
            </aside>
            <p v-if="error" class="alert error">{{ error }}</p>
            <p v-if="noticeText" class="admin-toast">{{ noticeText }}</p>
            <div v-if="!data" class="loading">Wird geladen…</div>
            <template v-else
                ><section
                    v-if="section === 'dashboard'"
                    class="admin-dashboard"
                >
                    <div class="admin-metrics">
                        <RouterLink
                            v-for="metric in data.metrics"
                            :key="metric.key"
                            :to="metric.to"
                            ><span>{{
                                metricLabels[metric.key] || metric.key
                            }}</span
                            ><strong>{{
                                metric.key === "mrr"
                                    ? `${Number(metric.value).toFixed(2)} €`
                                    : metric.value
                            }}</strong
                            ><small>Öffnen →</small></RouterLink
                        >
                    </div>
                    <div class="admin-dashboard-columns">
                        <section class="admin-dashboard-panel">
                            <div class="dashboard-panel-head">
                                <div>
                                    <p class="eyebrow">HEUTE</p>
                                    <h2>Zu erledigen</h2>
                                </div>
                                <span>{{ data.tasks.length }}</span>
                            </div>
                            <div
                                v-if="data.tasks.length"
                                class="admin-task-list"
                            >
                                <RouterLink
                                    v-for="task in data.tasks"
                                    :key="task.key"
                                    :to="task.to"
                                    :class="`severity-${task.severity}`"
                                    ><i>{{ task.count }}</i
                                    ><span
                                        ><b>{{ task.title }}</b
                                        ><small>{{
                                            task.description
                                        }}</small></span
                                    ><em>→</em></RouterLink
                                >
                            </div>
                            <div v-else class="dashboard-empty">
                                Keine offenen Pflichtaufgaben. Die Plattform ist
                                vollständig eingerichtet.
                            </div>
                        </section>
                        <section class="admin-dashboard-panel">
                            <div class="dashboard-panel-head">
                                <div>
                                    <p class="eyebrow">AKTUELL</p>
                                    <h2>Neueste Aktivitäten</h2>
                                </div>
                                <RouterLink to="/control/audit"
                                    >Alle →</RouterLink
                                >
                            </div>
                            <div class="admin-activity-list">
                                <RouterLink
                                    v-for="activity in data.recent"
                                    :key="activity.id"
                                    :to="activity.to"
                                    ><span
                                        ><b>{{ activity.title }}</b
                                        ><small>{{
                                            activity.description
                                        }}</small></span
                                    ><time>{{
                                        formatDate(activity.created_at)
                                    }}</time></RouterLink
                                >
                            </div>
                        </section>
                    </div>
                </section>
                <AdminSettings
                    v-else-if="section === 'settings'"
                    :data="data" />
                <section v-else-if="section === 'stripe'" class="stripe-status">
                    <article>
                        <p class="eyebrow">VERBINDUNG</p>
                        <h2>
                            {{
                                data.configured
                                    ? "Stripe verbunden"
                                    : "Stripe nicht konfiguriert"
                            }}
                        </h2>
                        <p v-if="data.account">
                            {{ data.account.id }} ·
                            {{
                                data.account.livemode
                                    ? "Live-Modus"
                                    : "Testmodus"
                            }}
                            · {{ data.account.country }}
                        </p>
                    </article>
                    <article>
                        <p class="eyebrow">WEBHOOK</p>
                        <h2>
                            {{ data.webhook_configured ? "Bereit" : "Fehlt" }}
                        </h2>
                        <p>
                            {{ data.plans_pending }} Tarife warten auf
                            Synchronisierung.
                        </p>
                    </article>
                    <button
                        class="button"
                        :disabled="busy || !data.configured"
                        @click="syncAllPlans"
                    >
                        Tarife synchronisieren
                    </button>
                </section>
                <section v-else class="admin-section registry-page">
                    <div v-if="section === 'sms'" class="sms-summary">
                        <article>
                            <span>Versendet im Monat</span
                            ><strong>{{ data.summary?.sent || 0 }}</strong>
                        </article>
                        <article>
                            <span>Zugestellt</span
                            ><strong>{{ data.summary?.delivered || 0 }}</strong>
                        </article>
                        <article>
                            <span>Fehlgeschlagen</span
                            ><strong>{{ data.summary?.failed || 0 }}</strong>
                        </article>
                        <article>
                            <span>Kosten im Monat</span
                            ><strong
                                >{{
                                    Number(data.summary?.cost || 0).toFixed(2)
                                }}
                                {{ data.summary?.currency || "EUR" }}</strong
                            >
                        </article>
                    </div>
                    <RegistryToolbar
                        v-model:search="filters.search"
                        :total="pager.total"
                        :add-label="addLabels[section]"
                        :busy="busy"
                        @add="openAdd"
                        @refresh="load"
                    >
                        <select
                            v-if="section === 'templates'"
                            v-model="filters.secondary"
                        >
                            <option value="">Alle Typen</option>
                            <option value="category">Kategorien</option>
                            <option value="variation">Varianten</option>
                            <option value="template">Vorlagen</option>
                        </select>
                        <select
                            v-else-if="section === 'subscriptions'"
                            v-model="filters.secondary"
                        >
                            <option value="">Alle Anbieter</option>
                            <option value="stripe">Stripe</option>
                            <option value="lookdo">
                                LOOKDO-Testphase
                            </option></select
                        ><select
                            v-else-if="section === 'ai'"
                            v-model="filters.secondary"
                        >
                            <option value="">Alle Sprachen</option>
                            <option value="de">Deutsch</option>
                            <option value="en">Englisch</option>
                            <option value="ru">Russisch</option>
                            <option value="uk">Ukrainisch</option></select
                        ><select
                            v-else-if="section === 'classifications'"
                            v-model="filters.secondary"
                        >
                            <option value="">Alle Quellen</option>
                            <option value="dictionary">Wörterbuch</option>
                            <option value="fuzzy">Ähnlichkeit</option>
                            <option value="ai">KI</option>
                            <option value="fallback">Standard</option>
                        </select>
                        <select
                            v-if="statusOptions[section]"
                            v-model="filters.status"
                        >
                            <option value="">Alle Status</option>
                            <option
                                v-for="option in statusOptions[section]"
                                :key="option[0]"
                                :value="option[0]"
                            >
                                {{ option[1] }}
                            </option></select
                        ><select v-model="filters.sort">
                            <option
                                v-for="option in sortOptions[section] || []"
                                :key="option[0]"
                                :value="option[0]"
                            >
                                {{ option[1] }}
                            </option></select
                        ><button
                            type="button"
                            class="sort-direction"
                            @click="
                                filters.direction =
                                    filters.direction === 'asc' ? 'desc' : 'asc'
                            "
                        >
                            {{
                                filters.direction === "asc" ? "↑" : "↓"
                            }}</button
                        ><select v-model.number="filters.per_page">
                            <option :value="10">10</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select></RegistryToolbar
                    >
                    <div class="admin-table-wrap">
                        <table>
                            <thead v-if="section === 'tenants'">
                                <tr>
                                    <th>Kunde</th>
                                    <th>Inhaber</th>
                                    <th>Vorlage</th>
                                    <th>Domain</th>
                                    <th>Tarif</th>
                                    <th>Zugang</th>
                                    <th>Konto</th>
                                </tr>
                            </thead>
                            <tbody v-if="section === 'tenants'">
                                <tr
                                    v-for="item in rows"
                                    :key="item.id"
                                    class="clickable"
                                    @click="openTenant(item)"
                                >
                                    <td>
                                        <b>{{ item.name }}</b
                                        ><small>{{ item.slug }}</small>
                                    </td>
                                    <td>
                                        <b>{{ item.users?.[0]?.name || "—" }}</b
                                        ><small>{{
                                            item.users?.[0]?.email || "—"
                                        }}</small>
                                    </td>
                                    <td>
                                        {{
                                            item.business_profile?.variation
                                                ?.name?.de ||
                                            item.business_profile?.variation
                                                ?.code ||
                                            "Standard"
                                        }}
                                    </td>
                                    <td>
                                        {{ item.primary_domain?.domain || "—" }}
                                    </td>
                                    <td>
                                        {{
                                            item.current_subscription?.plan
                                                ?.name?.de ||
                                            item.current_subscription?.plan
                                                ?.code ||
                                            "—"
                                        }}
                                    </td>
                                    <td>
                                        <span
                                            class="table-status"
                                            :class="tenantAccessClass(item)"
                                            >{{ tenantAccessLabel(item) }}</span
                                        >
                                    </td>
                                    <td>
                                        <span
                                            class="table-status"
                                            :class="item.status"
                                            >{{
                                                item.status === "active"
                                                    ? "technisch aktiv"
                                                    : item.status
                                            }}</span
                                        >
                                    </td>
                                </tr>
                            </tbody>

                            <thead v-if="section === 'administrators'">
                                <tr>
                                    <th>Administrator</th>
                                    <th>Rolle</th>
                                    <th>Letzte Anmeldung</th>
                                    <th>Seit</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody v-if="section === 'administrators'">
                                <tr v-for="item in rows" :key="item.id">
                                    <td>
                                        <b>{{ item.name }}</b
                                        ><small>{{ item.email }}</small>
                                    </td>
                                    <td>Super Administrator</td>
                                    <td>
                                        {{ formatDate(item.last_login_at) }}
                                    </td>
                                    <td>{{ formatDate(item.created_at) }}</td>
                                    <td>
                                        {{
                                            item.is_active
                                                ? "aktiv"
                                                : "gesperrt"
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                            <thead v-if="section === 'subscriptions'">
                                <tr>
                                    <th>Kunde</th>
                                    <th>Tarif</th>
                                    <th>Anbieter</th>
                                    <th>Status</th>
                                    <th>Periodenende</th>
                                    <th>Rabatt</th>
                                    <th>Provider-ID</th>
                                </tr>
                            </thead>
                            <tbody v-if="section === 'subscriptions'">
                                <tr v-for="item in rows" :key="item.id">
                                    <td>
                                        <b>{{ item.tenant?.name }}</b
                                        ><small>{{ item.tenant?.slug }}</small>
                                    </td>
                                    <td>
                                        {{
                                            item.plan?.name?.de ||
                                            item.plan?.code
                                        }}
                                    </td>
                                    <td>
                                        {{
                                            item.provider === "manual"
                                                ? "Manuell"
                                                : item.provider
                                        }}
                                    </td>
                                    <td>
                                        <span
                                            class="table-status"
                                            :class="
                                                subscriptionAccessClass(item)
                                            "
                                            >{{
                                                subscriptionAccessLabel(item)
                                            }}</span
                                        >
                                    </td>
                                    <td>
                                        {{
                                            formatDate(item.current_period_end)
                                        }}
                                    </td>
                                    <td>{{ item.discount_percent }}%</td>
                                    <td>
                                        <small>{{
                                            item.provider_subscription_id || "—"
                                        }}</small>
                                    </td>
                                </tr>
                            </tbody>
                            <thead v-if="section === 'plans'">
                                <tr>
                                    <th>Tarif</th>
                                    <th>Monat</th>
                                    <th>Jahr</th>
                                    <th>Module</th>
                                    <th>Kunden</th>
                                    <th>Sichtbarkeit</th>
                                    <th>Stripe</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody v-if="section === 'plans'">
                                <tr v-for="item in rows" :key="item.id">
                                    <td>
                                        <span class="plan-table-name"
                                            ><img
                                                loading="lazy"
                                                decoding="async"
                                                v-if="item.image_url"
                                                :src="item.image_url"
                                                alt=""
                                            /><span
                                                ><b>{{
                                                    item.name?.de || item.code
                                                }}</b
                                                ><small>{{
                                                    item.code
                                                }}</small></span
                                            ></span
                                        >
                                    </td>
                                    <td>
                                        {{ item.price_monthly }}
                                        {{ item.currency }}
                                    </td>
                                    <td>
                                        {{ item.price_yearly || "—" }}
                                        {{ item.currency }}
                                    </td>
                                    <td>
                                        {{ activeEntitlements(item) }} aktiv
                                    </td>
                                    <td>{{ item.subscriptions_count }}</td>
                                    <td>
                                        {{
                                            item.is_active
                                                ? item.is_public
                                                    ? "öffentlich"
                                                    : "intern"
                                                : "archiviert"
                                        }}
                                    </td>
                                    <td>
                                        {{
                                            item.stripe_synced_at
                                                ? "synchron"
                                                : item.stripe_sync_error ||
                                                  "offen"
                                        }}
                                    </td>
                                    <td class="table-actions">
                                        <button @click="editPlan(item)">
                                            Bearbeiten</button
                                        ><button @click="syncPlan(item)">
                                            Stripe
                                        </button>
                                    </td>
                                </tr>
                            </tbody>

                            <thead v-if="section === 'templates'">
                                <tr>
                                    <th>Typ</th>
                                    <th>Name</th>
                                    <th>Code</th>
                                    <th>Kategorie / Eltern</th>
                                    <th>Priorität / Version</th>
                                    <th>Status</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody v-if="section === 'templates'">
                                <tr
                                    v-for="item in rows"
                                    :key="`${item.kind}-${item.id}`"
                                    :class="{
                                        clickable: item.kind === 'template',
                                    }"
                                    @click="editTemplate(item)"
                                >
                                    <td>
                                        {{
                                            item.kind === "category"
                                                ? "Kategorie"
                                                : item.kind === "variation"
                                                  ? "Variante"
                                                  : "Vorlage"
                                        }}
                                    </td>
                                    <td>
                                        <b>{{ item.label }}</b>
                                    </td>
                                    <td>
                                        <code>{{ item.code }}</code>
                                    </td>
                                    <td>
                                        {{
                                            item.categoryLabel ||
                                            item.parent_code ||
                                            "—"
                                        }}
                                    </td>
                                    <td>
                                        {{
                                            item.kind === "template"
                                                ? `v${item.version}`
                                                : (item.priority ??
                                                  item.sort_order)
                                        }}
                                    </td>
                                    <td>
                                        {{ item.enabled ? "aktiv" : "inaktiv" }}
                                    </td>
                                    <td class="table-actions">
                                        <button
                                            v-if="item.kind === 'template'"
                                            @click.stop="editTemplate(item)"
                                        >
                                            Bearbeiten</button
                                        ><button
                                            @click.stop="toggleCatalog(item)"
                                        >
                                            {{
                                                item.enabled
                                                    ? "Deaktivieren"
                                                    : "Aktivieren"
                                            }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            <thead v-if="section === 'ai'">
                                <tr>
                                    <th>Begriff</th>
                                    <th>Sprache</th>
                                    <th>Kategorie</th>
                                    <th>Variante</th>
                                    <th>Gewichtung</th>
                                    <th>Status</th>
                                    <th>Aktion</th>
                                </tr>
                            </thead>
                            <tbody v-if="section === 'ai'">
                                <tr v-for="item in rows" :key="item.id">
                                    <td>
                                        <b>{{ item.phrase }}</b
                                        ><small>{{
                                            item.normalized_phrase
                                        }}</small>
                                    </td>
                                    <td>{{ item.locale.toUpperCase() }}</td>
                                    <td>
                                        {{
                                            item.category?.name?.de ||
                                            item.category?.code
                                        }}
                                    </td>
                                    <td>
                                        {{
                                            item.variation?.name?.de ||
                                            item.variation?.code ||
                                            "Standard"
                                        }}
                                    </td>
                                    <td>{{ item.weight }}</td>
                                    <td>
                                        {{ item.enabled ? "aktiv" : "inaktiv" }}
                                    </td>
                                    <td class="table-actions">
                                        <button @click="togglePhrase(item)">
                                            {{
                                                item.enabled
                                                    ? "Deaktivieren"
                                                    : "Aktivieren"
                                            }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            <thead v-if="section === 'classifications'">
                                <tr>
                                    <th>Datum</th>
                                    <th>Eingabe</th>
                                    <th>Kunde</th>
                                    <th>Ergebnis</th>
                                    <th>Sicherheit</th>
                                    <th>Quelle</th>
                                    <th>Bestätigt</th>
                                </tr>
                            </thead>
                            <tbody v-if="section === 'classifications'">
                                <tr v-for="item in rows" :key="item.id">
                                    <td>{{ formatDate(item.created_at) }}</td>
                                    <td>
                                        <b>{{ item.original_text }}</b
                                        ><small>{{
                                            item.normalized_text
                                        }}</small>
                                    </td>
                                    <td>
                                        {{
                                            item.tenant?.name || "Registrierung"
                                        }}
                                    </td>
                                    <td>
                                        {{
                                            item.category?.name?.de ||
                                            item.category?.code ||
                                            "Standard"
                                        }}<small>{{
                                            item.variation?.name?.de ||
                                            item.variation?.code ||
                                            "allgemein"
                                        }}</small>
                                    </td>
                                    <td>
                                        {{
                                            Math.round(
                                                Number(item.confidence) * 100,
                                            )
                                        }}%
                                    </td>
                                    <td>
                                        {{ item.source
                                        }}<small>{{ item.ai_model }}</small>
                                    </td>
                                    <td>
                                        {{
                                            item.confirmed_by_user_at
                                                ? "ja"
                                                : "nein"
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                            <thead v-if="section === 'content'">
                                <tr>
                                    <th>Seite</th>
                                    <th>URL</th>
                                    <th>Status</th>
                                    <th>Geändert</th>
                                    <th>Aktion</th>
                                </tr>
                            </thead>
                            <tbody v-if="section === 'content'">
                                <tr v-for="item in rows" :key="item.id">
                                    <td>
                                        <b>{{ item.label }}</b
                                        ><small>{{
                                            item.title?.ru || item.title?.en
                                        }}</small>
                                    </td>
                                    <td>
                                        <code>/{{ item.key }}</code>
                                    </td>
                                    <td>
                                        {{
                                            item.is_published
                                                ? "veröffentlicht"
                                                : "Entwurf"
                                        }}
                                    </td>
                                    <td>{{ formatDate(item.updated_at) }}</td>
                                    <td class="table-actions">
                                        <button @click="editPage(item)">
                                            Bearbeiten
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            <thead v-if="section === 'backups'">
                                <tr>
                                    <th>Backup</th>
                                    <th>Erstellt</th>
                                    <th>Dateien</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody v-if="section === 'backups'">
                                <tr v-for="item in rows" :key="item.name">
                                    <td>
                                        <b>{{ item.name }}</b>
                                    </td>
                                    <td>{{ formatDate(item.created_at) }}</td>
                                    <td>
                                        {{
                                            Object.keys(item.files || {}).length
                                        }}
                                    </td>
                                    <td class="table-actions">
                                        <button
                                            @click="
                                                backupAction(
                                                    'verify',
                                                    item.name,
                                                )
                                            "
                                        >
                                            Prüfen</button
                                        ><button
                                            class="danger"
                                            @click="
                                                backupAction(
                                                    'delete',
                                                    item.name,
                                                )
                                            "
                                        >
                                            Löschen
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            <thead v-if="section === 'sms'">
                                <tr>
                                    <th>Datum</th>
                                    <th>Kunde</th>
                                    <th>Ereignis</th>
                                    <th>Empfänger</th>
                                    <th>Status</th>
                                    <th>Teile</th>
                                    <th>Kosten</th>
                                    <th>Provider-ID / Fehler</th>
                                </tr>
                            </thead>
                            <tbody v-if="section === 'sms'">
                                <tr v-for="item in rows" :key="item.id">
                                    <td>{{ formatDate(item.created_at) }}</td>
                                    <td>
                                        <b>{{ item.tenant?.name || "—" }}</b
                                        ><small>{{ item.tenant?.slug }}</small>
                                    </td>
                                    <td>
                                        {{
                                            smsEventLabels[item.event_type] ||
                                            item.event_type
                                        }}
                                    </td>
                                    <td>{{ item.recipient_masked }}</td>
                                    <td>
                                        <span
                                            class="table-status"
                                            :class="item.status"
                                            >{{
                                                smsStatusLabels[item.status] ||
                                                item.status
                                            }}</span
                                        ><small>{{
                                            item.provider_status
                                        }}</small>
                                    </td>
                                    <td>{{ item.parts }}</td>
                                    <td>
                                        {{ Number(item.cost || 0).toFixed(4) }}
                                        {{ item.currency }}
                                    </td>
                                    <td>
                                        <small>{{
                                            item.provider_message_id ||
                                            item.error_message ||
                                            "—"
                                        }}</small>
                                    </td>
                                </tr>
                            </tbody>
                            <thead v-if="section === 'audit'">
                                <tr>
                                    <th>Datum</th>
                                    <th>Aktion</th>
                                    <th>Benutzer</th>
                                    <th>Kunde</th>
                                    <th>Objekt</th>
                                    <th>IP-Adresse</th>
                                </tr>
                            </thead>
                            <tbody v-if="section === 'audit'">
                                <tr
                                    v-for="item in rows"
                                    :key="item.id"
                                    class="audit-row"
                                    tabindex="0"
                                    @click="
                                        selectedAudit = item;
                                        modal = 'audit';
                                    "
                                    @keydown.enter="
                                        selectedAudit = item;
                                        modal = 'audit';
                                    "
                                >
                                    <td>{{ formatDate(item.created_at) }}</td>
                                    <td>
                                        <b>{{ item.action }}</b>
                                    </td>
                                    <td>{{ item.actor_id || "System" }}</td>
                                    <td>{{ item.tenant_id || "—" }}</td>
                                    <td>
                                        {{
                                            item.subject_type
                                                ? `${item.subject_type.split("\\").pop()} #${item.subject_id}`
                                                : "—"
                                        }}
                                    </td>
                                    <td>{{ item.ip_address || "—" }}</td>
                                </tr>
                            </tbody>
                            <tbody v-if="!rows.length">
                                <tr>
                                    <td colspan="9" class="empty-table">
                                        Keine passenden Einträge gefunden.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div
                        v-if="section === 'administrators'"
                        class="registry-context"
                    >
                        <span
                            >Super-Administratoren werden aus Sicherheitsgründen
                            ausschließlich über den geschützten Serverbefehl
                            angelegt. Kundenbenutzer können hier nicht zu
                            Administratoren gemacht werden.</span
                        >
                    </div>
                    <div v-if="section === 'backups'" class="registry-context">
                        <span>Speicherort: {{ data.path }}</span
                        ><span>Aufbewahrung: {{ data.keep }}</span
                        ><button
                            class="button"
                            :disabled="busy"
                            @click="backupAction('create')"
                        >
                            ＋ Backup erstellen
                        </button>
                    </div>
                    <AdminPagination
                        :current="pager.current"
                        :last="pager.last"
                        :from="pager.from"
                        :to="pager.to"
                        :total="pager.total"
                        @change="changePage"
                    /></section
            ></template>
        </main>
        <TemplateEditor
            v-if="selectedTemplate"
            :template="selectedTemplate"
            :categories="lookups.categories"
            :variations="lookups.variations"
            @close="selectedTemplate = null"
            @saved="templateSaved"
            @error="templateError"
        />
        <aside v-if="selectedTenant" class="drawer tenant-drawer">
            <button class="drawer-close" @click="selectedTenant = null">
                ×
            </button>
            <p class="eyebrow">KUNDE #{{ selectedTenant.id }}</p>
            <h2>{{ selectedTenant.name }}</h2>
            <p>
                {{
                    selectedTenant.primary_domain?.domain ||
                    selectedTenant.slug + ".lookdo.app"
                }}
            </p>

            <section class="tenant-drawer-section">
                <h3>Kunde und Inhaber</h3>
                <form
                    class="tenant-drawer-form"
                    @submit.prevent="saveTenantDetails"
                >
                    <label
                        >Unternehmen<input
                            v-model="selectedTenant.name"
                            required
                    /></label>
                    <label v-if="selectedTenant.users?.[0]"
                        >Name des Inhabers<input
                            v-model="selectedTenant.users[0].name"
                            required
                    /></label>
                    <label v-if="selectedTenant.users?.[0]"
                        >E-Mail des Inhabers<input
                            v-model="selectedTenant.users[0].email"
                            type="email"
                            required
                    /></label>
                    <button class="button small" :disabled="busy">
                        Stammdaten speichern
                    </button>
                </form>
                <dl v-if="selectedTenant.users?.[0]">
                    <div>
                        <dt>Zugang</dt>
                        <dd>
                            {{
                                selectedTenant.users[0].is_active
                                    ? "aktiv"
                                    : "gesperrt"
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt>Letzte Anmeldung</dt>
                        <dd>
                            {{
                                formatDate(
                                    selectedTenant.users[0].last_login_at,
                                )
                            }}
                        </dd>
                    </div>
                </dl>
                <div class="drawer-actions">
                    <button class="button" @click="impersonate">
                        Kundenkonto öffnen
                    </button>
                    <button
                        class="button ghost"
                        @click="toggleUser(selectedTenant.users[0])"
                    >
                        {{
                            selectedTenant.users[0]?.is_active
                                ? "Inhaber sperren"
                                : "Inhaber aktivieren"
                        }}
                    </button>
                    <button
                        class="button ghost"
                        @click="resetPassword(selectedTenant.users[0])"
                    >
                        Zugangslink senden
                    </button>
                </div>
            </section>

            <section class="tenant-drawer-section">
                <h3>Tarif und Zugang</h3>
                <template v-if="selectedTenant.current_subscription">
                    <div class="tenant-access-summary">
                        <span>Aktueller Zugang</span>
                        <strong>{{ tenantAccessLabel(selectedTenant) }}</strong>
                        <small
                            v-if="
                                selectedTenant.manual_access_until ||
                                selectedTenant.current_subscription
                                    .access_expires_at
                            "
                            >Bis
                            {{
                                formatDate(
                                    selectedTenant.manual_access_until ||
                                        selectedTenant.current_subscription
                                            .access_expires_at,
                                )
                            }}</small
                        >
                    </div>
                    <label
                        >Tarif<select
                            v-model.number="
                                selectedTenant.current_subscription.plan_id
                            "
                        >
                            <option
                                v-for="plan in lookups.plans"
                                :key="plan.id"
                                :value="plan.id"
                            >
                                {{ plan.name.de || plan.code }}
                            </option>
                        </select></label
                    >
                    <button
                        class="button ghost small"
                        @click="
                            updateTenant({
                                plan_id:
                                    selectedTenant.current_subscription.plan_id,
                                discount_percent:
                                    selectedTenant.current_subscription
                                        .discount_percent,
                            })
                        "
                    >
                        Tarif speichern
                    </button>
                    <div class="manual-access-form">
                        <label
                            >Ohne Zahlung freischalten (Tage)<input
                                v-model.number="manualAccessDays"
                                type="number"
                                min="1"
                                max="3650"
                        /></label>
                        <button
                            class="button small"
                            :disabled="busy || manualAccessDays < 1"
                            @click="grantTenantAccess"
                        >
                            Für {{ manualAccessDays }} Tage freischalten
                        </button>
                    </div>
                </template>
                <p v-else class="alert">
                    Für diesen Kunden ist noch kein Tarif hinterlegt.
                </p>
                <button
                    class="button ghost small"
                    @click="
                        updateTenant({
                            status:
                                selectedTenant.status === 'active'
                                    ? 'suspended'
                                    : 'active',
                        })
                    "
                >
                    {{
                        selectedTenant.status === "active"
                            ? "Technischen Zugang sperren"
                            : "Technischen Zugang aktivieren"
                    }}
                </button>
            </section>

            <section class="tenant-drawer-section">
                <h3>Domains</h3>
                <div class="tenant-domain-list">
                    <article
                        v-for="domain in selectedTenant.domains"
                        :key="domain.id"
                    >
                        <div>
                            <b>{{ domain.domain }}</b
                            ><small
                                >{{
                                    domain.type === "custom"
                                        ? "Eigene Domain"
                                        : "LOOKDO-Adresse"
                                }}
                                · {{ domain.status }} · SSL
                                {{ domain.ssl_status || "—" }}</small
                            >
                        </div>
                        <div
                            v-if="domain.type === 'custom'"
                            class="table-actions"
                        >
                            <button @click="domainAction(domain, 'verify')">
                                Prüfen
                            </button>
                            <button
                                v-if="domain.status === 'ssl_pending'"
                                @click="domainAction(domain, 'activate')"
                            >
                                Aktivieren
                            </button>
                            <button
                                v-if="domain.status === 'active'"
                                @click="domainAction(domain, 'disable')"
                            >
                                Deaktivieren
                            </button>
                            <button
                                v-if="domain.status !== 'active'"
                                class="danger"
                                @click="deleteDomain(domain)"
                            >
                                Löschen
                            </button>
                        </div>
                    </article>
                </div>
                <form
                    class="tenant-domain-add"
                    @submit.prevent="addTenantDomain"
                >
                    <input
                        v-model="customTenantDomain"
                        placeholder="firma.de"
                        required
                    />
                    <button class="button ghost small" :disabled="busy">
                        Domain hinzufügen
                    </button>
                </form>
            </section>

            <section class="tenant-drawer-section">
                <h3>Leistung überschreiben</h3>
                <input v-model="overrideForm.key" placeholder="Schlüssel" />
                <input v-model="overrideForm.value" placeholder="Wert" />
                <button class="button ghost small" @click="saveOverride">
                    Speichern
                </button>
            </section>

            <section class="tenant-drawer-section tenant-danger-zone">
                <h3>Kundenkonto löschen</h3>
                <p>
                    Entfernt den Kunden, seine lokalen Daten und Dateien
                    dauerhaft. Diese Aktion kann nicht rückgängig gemacht
                    werden.
                </p>
                <button
                    class="button ghost small danger"
                    :disabled="busy"
                    @click="deleteTenantPermanently"
                >
                    Kunden endgültig löschen
                </button>
            </section>
        </aside>
        <AdminModal
            v-if="modal === 'tenant'"
            title="Kunden anlegen"
            wide
            @close="modal = ''"
            ><form class="modal-form form-grid" @submit.prevent="createTenant">
                <label
                    >Unternehmen<input
                        v-model="tenantForm.name"
                        required /></label
                ><label
                    >Subdomain<input
                        v-model="tenantForm.slug"
                        placeholder="automatisch" /></label
                ><label
                    >Name des Inhabers<input
                        v-model="tenantForm.owner_name"
                        required /></label
                ><label
                    >E-Mail des Inhabers<input
                        v-model="tenantForm.owner_email"
                        type="email"
                        required /></label
                ><label
                    >Temporäres Passwort<input
                        v-model="tenantForm.owner_password"
                        type="password"
                        minlength="10"
                        required /></label
                ><label
                    >Tarif<select v-model.number="tenantForm.plan_id" required>
                        <option :value="null">Bitte wählen</option>
                        <option
                            v-for="plan in lookups.plans"
                            :key="plan.id"
                            :value="plan.id"
                        >
                            {{ plan.name.de || plan.code }}
                        </option>
                    </select></label
                ><label
                    >Geschäftsvorlage<select
                        v-model.number="tenantForm.variation_id"
                    >
                        <option :value="null">Standardvorlage</option>
                        <option
                            v-for="variation in lookups.variations"
                            :key="variation.id"
                            :value="variation.id"
                        >
                            {{ variation.name.de || variation.code }}
                        </option>
                    </select></label
                ><label class="check"
                    ><input
                        v-model="tenantForm.complimentary"
                        type="checkbox"
                    />
                    Ohne Zahlung freischalten</label
                ><label v-if="tenantForm.complimentary"
                    >Freischaltung (Tage)<input
                        v-model.number="tenantForm.complimentary_days"
                        type="number"
                        min="1"
                        max="3650"
                        required /></label
                ><label class="wide"
                    >Tätigkeitsbeschreibung<textarea
                        v-model="tenantForm.business_description"
                    ></textarea>
                </label>
                <div class="modal-actions wide">
                    <button
                        type="button"
                        class="button ghost"
                        @click="modal = ''"
                    >
                        Abbrechen</button
                    ><button class="button" :disabled="busy">
                        Kunden anlegen
                    </button>
                </div>
            </form></AdminModal
        >
        <AdminModal
            v-if="modal === 'plan'"
            :title="editingPlanId ? 'Tarif bearbeiten' : 'Tarif anlegen'"
            wide
            @close="modal = ''"
        >
            <form
                class="modal-form form-grid plan-editor"
                @submit.prevent="savePlan"
            >
                <section class="plan-editor-section plan-image-editor wide">
                    <div>
                        <h3>Tarifbild</h3>
                        <p>
                            Dieses Bild erscheint dezent im Kopf der Tarifkarte
                            und wird bei der Stripe-Synchronisierung an das
                            Produkt übertragen.
                        </p>
                        <small
                            >Empfohlen: Querformat, mindestens 1200 × 630 Pixel.
                            JPG, PNG oder WebP, maximal 8 MB.</small
                        >
                    </div>
                    <div class="plan-image-editor-layout">
                        <div
                            class="plan-image-admin-preview"
                            :class="{ empty: !planImagePreview }"
                        >
                            <img
                                decoding="async"
                                v-if="planImagePreview"
                                :src="planImagePreview"
                                alt="Tarifbild"
                            /><span v-else>Noch kein Tarifbild</span>
                        </div>
                        <div class="plan-image-editor-actions">
                            <label class="media-file-button"
                                ><input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    :disabled="busy"
                                    @change="selectPlanImage"
                                /><span>Bild auswählen</span></label
                            ><button
                                v-if="planImageFile"
                                type="button"
                                class="button ghost small"
                                @click="clearPendingPlanImage"
                            >
                                Auswahl verwerfen</button
                            ><button
                                v-else-if="editingPlanId && planImagePreview"
                                type="button"
                                class="button ghost small danger"
                                :disabled="busy"
                                @click="deletePlanImage"
                            >
                                Bild entfernen
                            </button>
                        </div>
                    </div>
                </section>
                <section class="plan-editor-section wide">
                    <h3>Abrechnung & Veröffentlichung</h3>
                    <p>
                        Monats- und Jahrespreise werden je Währung fest
                        hinterlegt. So kann ein Jahrespreis einen eigenen Rabatt
                        enthalten, ohne automatische Wechselkursberechnung.
                    </p>
                    <div class="plan-currency-grid">
                        <fieldset
                            v-for="currency in ['EUR', 'RUB', 'UAH']"
                            :key="currency"
                            class="plan-currency-card"
                        >
                            <legend>{{ currency }}</legend>
                            <label
                                >Monatspreis<input
                                    v-model.number="
                                        planForm.prices[currency].monthly
                                    "
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    required /></label
                            ><label
                                >Jahrespreis<input
                                    v-model.number="
                                        planForm.prices[currency].yearly
                                    "
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    required
                            /></label>
                        </fieldset>
                    </div>
                    <div class="form-grid plan-publishing">
                        <label
                            >Code<input
                                v-model="planForm.code"
                                required /></label
                        ><label
                            >Testtage<input
                                v-model.number="planForm.trial_days"
                                type="number"
                                min="0" /></label
                        ><label
                            >Reihenfolge<input
                                v-model.number="planForm.sort_order"
                                type="number"
                                min="0" /></label
                        ><label class="check"
                            ><input
                                v-model="planForm.is_active"
                                type="checkbox"
                            />
                            Aktiv</label
                        ><label class="check"
                            ><input
                                v-model="planForm.is_public"
                                type="checkbox"
                            />
                            Öffentlich</label
                        >
                    </div>
                </section>
                <section class="plan-editor-section wide">
                    <div class="plan-section-head">
                        <div>
                            <h3>Texte in vier Sprachen</h3>
                            <p>
                                Wählen Sie die Sprache Ihres Ausgangstextes. Die
                                KI übersetzt Name, Beschreibung und Badge in die
                                drei anderen Sprachen.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="button small"
                            :disabled="translating"
                            @click="translatePlan"
                        >
                            {{
                                translating
                                    ? "KI übersetzt…"
                                    : "✦ Mit KI übersetzen"
                            }}
                        </button>
                    </div>
                    <div class="type-tabs plan-language-tabs">
                        <button
                            v-for="item in planLocales"
                            :key="item[0]"
                            type="button"
                            :class="{ active: planLocale === item[0] }"
                            @click="planLocale = item[0]"
                        >
                            {{ item[1]
                            }}<span v-if="planForm.name[item[0]]">✓</span>
                        </button>
                    </div>
                    <div class="form-grid">
                        <label
                            >Tarifname ({{
                                planLocales.find(
                                    (item) => item[0] === planLocale,
                                )?.[1]
                            }})<input
                                v-model="planForm.name[planLocale]"
                                required /></label
                        ><label
                            >Badge / Kennzeichnung<input
                                v-model="planForm.badge_text[planLocale]"
                                placeholder="z. B. Empfohlen" /></label
                        ><label class="wide"
                            >Beschreibung<textarea
                                v-model="planForm.description[planLocale]"
                                rows="3"
                            ></textarea>
                        </label>
                    </div>
                    <p class="plan-translation-note">
                        KI-Texte werden vor dem Speichern in die Felder
                        eingefügt und können manuell geprüft oder geändert
                        werden. Preise, Limits und Leistungen werden nicht von
                        der KI verändert.
                    </p>
                </section>
                <section class="plan-editor-section wide">
                    <h3>Leistungen & Limits</h3>
                    <p>
                        Diese Werte steuern die öffentliche Tarifdarstellung und
                        später die Freischaltung der Funktionen im Kundenkonto.
                    </p>
                    <div class="entitlement-groups">
                        <fieldset
                            v-for="group in entitlementGroups"
                            :key="group.key"
                            class="entitlement-group"
                        >
                            <legend>{{ group.label }}</legend>
                            <div class="entitlement-fields">
                                <template
                                    v-for="item in group.items"
                                    :key="item.key"
                                    ><label
                                        v-if="item.type === 'number'"
                                        class="entitlement-number"
                                        >{{ item.label
                                        }}<input
                                            v-model="
                                                planForm.entitlements[item.key]
                                            "
                                            type="number"
                                            :min="item.min"
                                            :max="item.max"
                                            required
                                        /><small v-if="item.help">{{
                                            item.help
                                        }}</small></label
                                    ><label
                                        v-else
                                        class="check entitlement-check"
                                        ><input
                                            v-model="
                                                planForm.entitlements[item.key]
                                            "
                                            type="checkbox"
                                            true-value="1"
                                            false-value="0"
                                        /><span
                                            ><b>{{ item.label }}</b
                                            ><small v-if="item.help">{{
                                                item.help
                                            }}</small></span
                                        ></label
                                    ></template
                                >
                            </div>
                        </fieldset>
                    </div>
                </section>
                <div class="modal-actions wide">
                    <button
                        type="button"
                        class="button ghost"
                        @click="modal = ''"
                    >
                        Abbrechen</button
                    ><button class="button" :disabled="busy || translating">
                        Tarif speichern
                    </button>
                </div>
            </form>
        </AdminModal>
        <AdminModal
            v-if="modal === 'phrase'"
            title="Geschäftsbegriff hinzufügen"
            @close="modal = ''"
            ><form class="modal-form" @submit.prevent="savePhrase">
                <label
                    >Sprache<select v-model="phraseForm.locale">
                        <option value="de">Deutsch</option>
                        <option value="en">Englisch</option>
                        <option value="ru">Russisch</option>
                        <option value="uk">Ukrainisch</option>
                    </select></label
                ><label
                    >Variante<select
                        v-model.number="phraseForm.variation_id"
                        required
                        @change="choosePhraseVariation"
                    >
                        <option :value="null">Bitte wählen</option>
                        <option
                            v-for="variation in lookups.variations"
                            :key="variation.id"
                            :value="variation.id"
                        >
                            {{
                                variation.name.de ||
                                variation.name.ru ||
                                variation.code
                            }}
                        </option>
                    </select></label
                ><label
                    >Begriff<input
                        v-model="phraseForm.phrase"
                        required /></label
                ><label
                    >Gewichtung<input
                        v-model.number="phraseForm.weight"
                        type="number"
                        min="0.1"
                        max="5"
                        step="0.1"
                /></label>
                <div class="modal-actions">
                    <button
                        type="button"
                        class="button ghost"
                        @click="modal = ''"
                    >
                        Abbrechen</button
                    ><button class="button" :disabled="busy">Hinzufügen</button>
                </div>
            </form></AdminModal
        >
        <AdminModal
            v-if="modal === 'catalog'"
            title="Vorlagen-Eintrag anlegen"
            wide
            @close="modal = ''"
            ><div class="type-tabs">
                <button
                    v-for="kind in [
                        ['category', 'Kategorie'],
                        ['variation', 'Variante'],
                        ['template', 'Vorlage'],
                    ]"
                    :key="kind[0]"
                    :class="{ active: catalogKind === kind[0] }"
                    @click="catalogKind = kind[0]"
                >
                    {{ kind[1] }}
                </button>
            </div>
            <form class="modal-form form-grid" @submit.prevent="saveCatalog">
                <template v-if="catalogKind === 'category'"
                    ><label
                        >Code<input
                            v-model="categoryForm.code"
                            required /></label
                    ><label
                        >Reihenfolge<input
                            v-model.number="categoryForm.sort_order"
                            type="number" /></label
                    ><label
                        >Deutsch<input
                            v-model="categoryForm.name.de"
                            required /></label
                    ><label
                        >Russisch<input
                            v-model="categoryForm.name.ru"
                            required /></label></template
                ><template v-if="catalogKind === 'variation'"
                    ><label
                        >Kategorie<select
                            v-model.number="variationForm.category_id"
                            required
                        >
                            <option
                                v-for="category in lookups.categories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name.de || category.code }}
                            </option>
                        </select></label
                    ><label
                        >Code<input
                            v-model="variationForm.code"
                            required /></label
                    ><label
                        >Deutsch<input
                            v-model="variationForm.name.de"
                            required /></label
                    ><label
                        >Russisch<input
                            v-model="variationForm.name.ru"
                            required /></label
                    ><label
                        >Vorlagen-Code<input
                            v-model="variationForm.template_code" /></label
                    ><label
                        >Priorität<input
                            v-model.number="variationForm.priority"
                            type="number" /></label></template
                ><template v-if="catalogKind === 'template'"
                    ><label
                        >Kategorie<select
                            v-model.number="templateForm.category_id"
                        >
                            <option :value="null">Keine</option>
                            <option
                                v-for="category in lookups.categories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name.de || category.code }}
                            </option>
                        </select></label
                    ><label
                        >Variante<select
                            v-model.number="templateForm.variation_id"
                        >
                            <option :value="null">Keine</option>
                            <option
                                v-for="variation in lookups.variations"
                                :key="variation.id"
                                :value="variation.id"
                            >
                                {{ variation.name.de || variation.code }}
                            </option>
                        </select></label
                    ><label
                        >Code<input
                            v-model="templateForm.code"
                            required /></label
                    ><label
                        >Übergeordneter Code<input
                            v-model="templateForm.parent_code" /></label
                    ><label
                        >Version<input
                            v-model.number="templateForm.version"
                            type="number" /></label
                    ><label
                        >Name Deutsch<input
                            v-model="templateForm.name.de"
                            required /></label
                    ><label
                        >Name English<input
                            v-model="templateForm.name.en" /></label
                    ><label
                        >Name Русский<input
                            v-model="templateForm.name.ru" /></label
                    ><label
                        >Name Українська<input v-model="templateForm.name.uk"
                    /></label>
                    <div class="registry-context wide">
                        <span
                            >Nach dem Anlegen öffnet sich der visuelle Editor
                            für Bilder, Farben, Bildschirme, Blöcke,
                            Formularfelder, Foto-Schritte, Aktionen und
                            KI-Begriffe.</span
                        >
                    </div></template
                >
                <div class="modal-actions wide">
                    <button
                        type="button"
                        class="button ghost"
                        @click="modal = ''"
                    >
                        Abbrechen</button
                    ><button class="button" :disabled="busy">Anlegen</button>
                </div>
            </form></AdminModal
        >
        <AdminModal
            v-if="modal === 'page' && selectedPage"
            title="Inhaltsseite bearbeiten"
            wide
            @close="closePageEditor"
            ><form class="modal-form" @submit.prevent="savePage">
                <div class="editor-head">
                    <code>/{{ selectedPage.key }}</code
                    ><label class="check"
                        ><input
                            v-model="selectedPage.is_published"
                            type="checkbox"
                        />
                        Veröffentlicht</label
                    >
                </div>
                <div class="plan-section-head">
                    <div>
                        <p>
                            Ausgangssprache wählen, Text schreiben und
                            anschließend die anderen drei Sprachen mit KI
                            vorbereiten.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="button small"
                        :disabled="translating"
                        @click="translatePage"
                    >
                        {{
                            translating
                                ? "KI übersetzt…"
                                : "✦ Mit KI übersetzen"
                        }}
                    </button>
                </div>
                <div
                    v-if="
                        pageTranslation.pageId === selectedPage.id &&
                        pageTranslation.phase === 'running'
                    "
                    class="translation-inline working"
                >
                    <span>✦</span>
                    <div>
                        <b>KI-Übersetzung läuft.</b
                        ><small
                            >Dieses Fenster können Sie schließen. Bitte den
                            Browser-Tab geöffnet lassen; das Ergebnis erscheint
                            anschließend oben in der Verwaltung.</small
                        >
                    </div>
                </div>
                <div
                    v-else-if="
                        pageTranslation.pageId === selectedPage.id &&
                        pageTranslation.phase === 'ready'
                    "
                    class="translation-inline ready"
                >
                    <span>✓</span>
                    <div>
                        <b>Übersetzung fertig.</b
                        ><small
                            >Die Texte wurden als Entwurf eingesetzt. Bitte
                            prüfen und anschließend speichern.</small
                        >
                    </div>
                </div>
                <div
                    v-else-if="
                        pageTranslation.pageId === selectedPage.id &&
                        pageTranslation.phase === 'error'
                    "
                    class="translation-inline failed"
                >
                    <span>!</span>
                    <div>
                        <b>Übersetzung fehlgeschlagen.</b
                        ><small>{{ pageTranslation.message }}</small>
                    </div>
                </div>
                <div class="type-tabs">
                    <button
                        v-for="locale in planLocales"
                        :key="locale[0]"
                        type="button"
                        :class="{ active: contentLocale === locale[0] }"
                        @click="contentLocale = locale[0]"
                    >
                        {{ locale[1]
                        }}<span v-if="selectedPage.title[locale[0]]"> ✓</span>
                    </button>
                </div>
                <label
                    >Titel<input
                        v-model="selectedPage.title[contentLocale]" /></label
                ><label
                    >Inhalt<RichContentEditor
                        v-model="selectedPage.content[contentLocale]"
                /></label>
                <p class="plan-translation-note">
                    Das Bearbeitungsfenster darf während der Übersetzung
                    geschlossen werden; der Browser-Tab muss geöffnet bleiben.
                    Das Ergebnis wird als Entwurf eingesetzt und erst nach Ihrer
                    Prüfung gespeichert. Platzhalter, Links und HTML bleiben
                    erhalten.
                </p>
                <div class="modal-actions">
                    <button
                        type="button"
                        class="button ghost"
                        @click="closePageEditor"
                    >
                        Schließen</button
                    ><button class="button" :disabled="busy || translating">
                        Speichern
                    </button>
                </div>
            </form></AdminModal
        >
        <AdminModal
            v-if="modal === 'confirm' && confirmAction"
            :title="confirmAction.title"
            @close="
                modal = '';
                confirmAction = null;
            "
            ><div class="confirm-dialog">
                <p>{{ confirmAction.message }}</p>
                <div class="modal-actions">
                    <button
                        type="button"
                        class="button ghost"
                        @click="
                            modal = '';
                            confirmAction = null;
                        "
                    >
                        Abbrechen</button
                    ><button
                        type="button"
                        class="button"
                        :class="{ danger: confirmAction.danger }"
                        :disabled="busy"
                        @click="executeConfirmed"
                    >
                        {{ confirmAction.confirmLabel }}
                    </button>
                </div>
            </div></AdminModal
        >
        <AuditDetailsModal
            v-if="modal === 'audit' && selectedAudit"
            :audit="selectedAudit"
            @close="modal = ''; selectedAudit = null"
        />
    </div>
</template>

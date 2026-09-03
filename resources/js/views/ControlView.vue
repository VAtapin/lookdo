<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api } from "../api";
import { createControlState } from "../control/state";
import { usePageTranslations } from "../control/page-translations";
import {
    addLabels,
    endpoint,
    metricLabels,
    nav,
    planLocales,
    serverSections,
    smsEventLabels,
    smsStatusLabels,
    sortOptions,
    statusOptions,
    subscriptionAccessClass,
    subscriptionAccessLabel,
    tenantAccessClass,
    tenantAccessLabel,
} from "../control/config";
import ControlCatalogModals from "../components/admin/ControlCatalogModals.vue";
import ControlAdministratorModal from "../components/admin/ControlAdministratorModal.vue";
import ControlConfirmationModals from "../components/admin/ControlConfirmationModals.vue";
import ControlPageModal from "../components/admin/ControlPageModal.vue";
import ControlPlanModal from "../components/admin/ControlPlanModal.vue";
import ControlOverview from "../components/admin/ControlOverview.vue";
import ControlRegistry from "../components/admin/ControlRegistry.vue";
import ControlTenantCreateModal from "../components/admin/ControlTenantCreateModal.vue";
import ControlTenantDrawer from "../components/admin/ControlTenantDrawer.vue";
import ControlSubscriptionModal from "../components/admin/ControlSubscriptionModal.vue";
import ControlTranslationJob from "../components/admin/ControlTranslationJob.vue";
import TemplateEditor from "../components/admin/TemplateEditor.vue";

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
const selectedAdministrator = ref<any>(null);
const selectedSubscription = ref<any>(null);
const selectedPage = ref<any>(null);
const selectedTemplate = ref<any>(null);
const selectedAudit = ref<any>(null);
const backupTenantId = ref<number | string>("");
const auditCleanupScope = ref("90");
const confirmAction = ref<any>(null);
const administratorForm = reactive<any>({
    name: "",
    email: "",
    locale: "de",
    is_active: true,
    password: "",
    password_confirmation: "",
});
const subscriptionPaymentForm = reactive<any>({
    amount: 0,
    currency: "EUR",
    payment_method: "cash",
    paid_at: "",
    reference: "",
    note: "",
    grant_access: true,
    access_until: "",
    subscription_invoice_id: "",
});
const subscriptionInvoiceForm = reactive<any>({
    amount_total: 0,
    tax_rate: 19,
    currency: "EUR",
    issue_date: "",
    due_date: "",
    period_start: "",
    period_end: "",
    description: "LOOKDO Abonnement",
    notes: "",
});
const subscriptionStatusForm = reactive<any>({
    status: "incomplete",
    current_period_end: "",
    cancel_at_period_end: false,
    reason: "",
});
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
const {
    filters,
    tenantForm,
    customTenantDomain,
    manualAccessDays,
    planForm,
    planImageFile,
    planImagePreview,
    planExistingImage,
    phraseForm,
    categoryForm,
    variationForm,
    templateForm,
    overrideForm,
    smsOverrideForm,
} = createControlState();
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
        } else if (section.value === "backups") {
            const params = new URLSearchParams(queryString());
            if (backupTenantId.value)
                params.set("tenant_id", String(backupTenantId.value));
            data.value = await api(
                `${url}?${params.toString()}`,
            );
            backupTenantId.value = data.value?.selected_tenant_id || "";
        } else
            data.value = await api(
                serverSections.has(section.value)
                    ? `${url}?${queryString()}`
                    : url,
            );
        if (["tenants", "ai", "templates"].includes(section.value)) {
            const [plans, taxonomy, catalog] = await Promise.all([
                api<any[]>("/control/plans"),
                api<any>("/control/taxonomy"),
                section.value === "tenants"
                    ? api<any>("/control/plan-entitlements")
                    : Promise.resolve(null),
            ]);
            lookups.value = {
                plans,
                categories: taxonomy.categories,
                variations: taxonomy.categories.flatMap(
                    (category: any) => category.variations,
                ),
            };
            if (catalog) entitlementCatalog.value = catalog;
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
        secondary: section.value === "templates" ? "template" : "",
        actor_id: "",
        tenant_id: "",
        action: "",
        sort: sortOptions[section.value]?.[0]?.[0] || "created_at",
        direction: "desc",
        per_page: 25,
        page: 1,
    });
}
resetFilters();
let debounce: number | undefined;
watch(section, () => {
    selectedTenant.value = null;
    selectedSubscription.value = null;
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
const selectedOverrideDefinition = computed<any>(() =>
    overrideForm.key
        ? entitlementCatalog.value.definitions?.[overrideForm.key] || null
        : null,
);
function tenantHasOverride(key: string) {
    return Object.prototype.hasOwnProperty.call(
        selectedTenant.value?.entitlement_overrides || {},
        key,
    );
}
function effectiveTenantEntitlement(key: string, fallback: string | number) {
    return selectedTenant.value?.entitlements?.[key] ?? fallback;
}
function hydrateTenantEntitlementForms() {
    const enabled = effectiveTenantEntitlement("sms_enabled", "0");
    const limit = Number(
        effectiveTenantEntitlement("sms_monthly_limit", "0"),
    );
    smsOverrideForm.enabled = [true, 1, "1", "true"].includes(enabled);
    smsOverrideForm.monthly_limit = Number.isFinite(limit) && limit > 0
        ? limit
        : 50;
    overrideForm.key = "";
    overrideForm.value = "";
}
function selectOverride() {
    if (!overrideForm.key) {
        overrideForm.value = "";
        return;
    }
    const definition = selectedOverrideDefinition.value;
    overrideForm.value = String(
        effectiveTenantEntitlement(
            overrideForm.key,
            definition?.default ?? (definition?.type === "boolean" ? 0 : ""),
        ),
    );
}
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
    if (section.value === "administrators") {
        selectedAdministrator.value = null;
        Object.assign(administratorForm, {
            name: "",
            email: "",
            locale: "de",
            is_active: true,
            password: "",
            password_confirmation: "",
        });
        modal.value = "administrator";
    }
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
function openAdministrator(administrator: any) {
    selectedAdministrator.value = administrator;
    Object.assign(administratorForm, {
        name: administrator.name || "",
        email: administrator.email || "",
        locale: administrator.locale || "de",
        is_active: Boolean(administrator.is_active),
        password: "",
        password_confirmation: "",
    });
    modal.value = "administrator";
}
async function saveAdministrator() {
    busy.value = true;
    error.value = "";
    try {
        const editing = selectedAdministrator.value;
        await api(
            editing
                ? `/control/administrators/${editing.id}`
                : "/control/administrators",
            {
                method: editing ? "PUT" : "POST",
                body: JSON.stringify(administratorForm),
            },
        );
        modal.value = "";
        selectedAdministrator.value = null;
        toast(
            editing
                ? "Administrator wurde aktualisiert."
                : "Administrator wurde angelegt.",
        );
        await load();
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
function askDeleteAdministrator(administrator: any) {
    askConfirmation({
        title: "Administrator löschen?",
        message: `${administrator.name} (${administrator.email}) verliert den vollständigen Verwaltungszugriff. Diese Aktion kann nicht rückgängig gemacht werden.`,
        confirmLabel: "Administrator löschen",
        danger: true,
        run: async () => {
            await api(`/control/administrators/${administrator.id}`, {
                method: "DELETE",
            });
            selectedAdministrator.value = null;
            toast("Administrator wurde gelöscht.");
        },
    });
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
function linkedTemplate(item: any) {
    if (item.kind === "template") return item;
    const templates = data.value?.templates || [];
    if (item.kind === "variation" && item.template_code)
        return templates.find(
            (template: any) => template.code === item.template_code,
        );
    if (item.kind === "category")
        return templates.find(
            (template: any) =>
                Number(template.category_id) === Number(item.id) &&
                !template.variation_id,
        );
    return null;
}
function hasEditableTemplate(item: any) {
    return Boolean(linkedTemplate(item));
}
function editTemplate(item: any) {
    const template = linkedTemplate(item);
    if (!template) {
        error.value = "Für diesen Katalogeintrag ist noch keine App-Vorlage verknüpft.";
        return;
    }
    selectedTemplate.value = JSON.parse(
        JSON.stringify({
            ...template,
            kind: "template",
            label: template.name?.de || template.name?.ru || template.code,
        }),
    );
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
        hydrateTenantEntitlementForms();
    } catch (exception: any) {
        error.value = exception.message;
        selectedTenant.value = null;
    }
}
function dateTimeLocal(value: string | Date | null) {
    if (!value) return "";
    const date = new Date(value);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
    return local.toISOString().slice(0, 16);
}
async function openSubscription(item: any, focus = "details") {
    error.value = "";
    try {
        const subscription = await api(`/control/subscriptions/${item.id}`);
        selectedSubscription.value = subscription;
        const monthly = Number(subscription.unit_amount || subscription.plan?.price_monthly || 0);
        const discount = Number(subscription.discount_percent || 0);
        const paidAt = new Date();
        const accessUntil = new Date(paidAt);
        accessUntil.setMonth(accessUntil.getMonth() + 1);
        Object.assign(subscriptionPaymentForm, {
            amount: Number((monthly * (100 - discount) / 100).toFixed(2)),
            currency: subscription.currency || subscription.plan?.currency || "EUR",
            payment_method: "cash",
            paid_at: dateTimeLocal(paidAt),
            reference: "",
            note: "",
            grant_access: true,
            access_until: dateTimeLocal(accessUntil),
            subscription_invoice_id: "",
        });
        const issueDate = new Date();
        const dueDate = new Date(issueDate);
        dueDate.setDate(dueDate.getDate() + 14);
        Object.assign(subscriptionInvoiceForm, {
            amount_total: Number((monthly * (100 - discount) / 100).toFixed(2)),
            tax_rate: 19,
            currency: subscription.currency || subscription.plan?.currency || "EUR",
            issue_date: dateTimeLocal(issueDate).slice(0, 10),
            due_date: dateTimeLocal(dueDate).slice(0, 10),
            period_start: dateTimeLocal(issueDate),
            period_end: dateTimeLocal(accessUntil),
            description: `LOOKDO ${subscription.plan?.name?.de || subscription.plan?.code || "Abonnement"}`,
            notes: "",
        });
        Object.assign(subscriptionStatusForm, {
            status: subscription.status,
            current_period_end: dateTimeLocal(subscription.current_period_end),
            cancel_at_period_end: Boolean(subscription.cancel_at_period_end),
            reason: "",
        });
        await nextTick();
        document
            .getElementById(`subscription-${focus}`)
            ?.scrollIntoView({ behavior: "smooth", block: "start" });
    } catch (exception: any) {
        error.value = exception.message;
        selectedSubscription.value = null;
    }
}
function closeSubscription() {
    selectedSubscription.value = null;
}
async function saveSubscriptionPayment() {
    if (!selectedSubscription.value) return;
    busy.value = true;
    error.value = "";
    try {
        selectedSubscription.value = await api(`/control/subscriptions/${selectedSubscription.value.id}/payments`, {
            method: "POST",
            body: JSON.stringify(subscriptionPaymentForm),
        });
        Object.assign(subscriptionStatusForm, {
            status: selectedSubscription.value.status,
            current_period_end: dateTimeLocal(selectedSubscription.value.current_period_end),
            cancel_at_period_end: Boolean(selectedSubscription.value.cancel_at_period_end),
            reason: "",
        });
        subscriptionPaymentForm.reference = "";
        subscriptionPaymentForm.note = "";
        subscriptionPaymentForm.subscription_invoice_id = "";
        await load();
        toast("Zahlung wurde erfasst. Der Beleg ist jetzt druckbar.");
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
async function saveSubscriptionInvoice() {
    if (!selectedSubscription.value) return;
    busy.value = true;
    error.value = "";
    try {
        selectedSubscription.value = await api(`/control/subscriptions/${selectedSubscription.value.id}/invoices`, {
            method: "POST",
            body: JSON.stringify(subscriptionInvoiceForm),
        });
        subscriptionInvoiceForm.notes = "";
        await load();
        toast("Rechnung wurde ausgestellt und ist im Kundenkonto sichtbar.");
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
async function voidSubscriptionInvoice(invoice: any) {
    if (!selectedSubscription.value) return;
    const reason = window.prompt("Grund für die Stornierung der Rechnung:");
    if (!reason) return;
    busy.value = true;
    error.value = "";
    try {
        selectedSubscription.value = await api(`/control/subscriptions/${selectedSubscription.value.id}/invoices/${invoice.id}`, {
            method: "PATCH",
            body: JSON.stringify({ status: "void", reason }),
        });
        await load();
        toast("Rechnung wurde storniert.");
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
function prepareInvoicePayment(invoice: any) {
    subscriptionPaymentForm.subscription_invoice_id = invoice.id;
    subscriptionPaymentForm.amount = Number(invoice.amount_total);
    subscriptionPaymentForm.currency = invoice.currency;
    subscriptionPaymentForm.reference = invoice.invoice_number;
    subscriptionPaymentForm.payment_method = "cash";
}
function openInvoiceDocument(invoice: any) {
    if (!selectedSubscription.value) return;
    window.open(`/api/control/subscriptions/${selectedSubscription.value.id}/invoices/${invoice.id}`, "_blank", "noopener,noreferrer");
}
async function saveSubscriptionStatus() {
    if (!selectedSubscription.value) return;
    busy.value = true;
    error.value = "";
    try {
        selectedSubscription.value = await api(`/control/subscriptions/${selectedSubscription.value.id}/status`, {
            method: "PATCH",
            body: JSON.stringify(subscriptionStatusForm),
        });
        subscriptionStatusForm.reason = "";
        await load();
        toast("Abonnementstatus wurde manuell geändert und protokolliert.");
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
function openPaymentReceipt(payment: any) {
    if (!selectedSubscription.value) return;
    window.open(`/api/control/subscriptions/${selectedSubscription.value.id}/payments/${payment.id}/receipt`, "_blank", "noopener,noreferrer");
}
async function refreshSelectedTenant() {
    if (selectedTenant.value?.id) {
        selectedTenant.value = await api(
            `/control/tenants/${selectedTenant.value.id}`,
        );
        hydrateTenantEntitlementForms();
    }
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
        hydrateTenantEntitlementForms();
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
    if (!overrideForm.key) return;
    busy.value = true;
    error.value = "";
    try {
        await api(`/control/tenants/${selectedTenant.value.id}/entitlement`, {
            method: "PUT",
            body: JSON.stringify(overrideForm),
        });
        await refreshSelectedTenant();
        toast("Individuelle Leistung wurde gespeichert.");
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
async function saveSmsOverrides() {
    busy.value = true;
    error.value = "";
    try {
        const monthlyLimit = Math.max(
            1,
            Math.min(10000, Math.round(Number(smsOverrideForm.monthly_limit))),
        );
        smsOverrideForm.monthly_limit = monthlyLimit;
        await api(`/control/tenants/${selectedTenant.value.id}/entitlement`, {
            method: "PUT",
            body: JSON.stringify({
                overrides: [
                    {
                        key: "sms_enabled",
                        value: smsOverrideForm.enabled ? "1" : "0",
                    },
                    {
                        key: "sms_monthly_limit",
                        value: String(monthlyLimit),
                    },
                ],
            }),
        });
        await refreshSelectedTenant();
        toast("SMS-Einstellungen wurden gespeichert.");
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
async function clearOverrides(keys: string[], message: string) {
    busy.value = true;
    error.value = "";
    try {
        await api(`/control/tenants/${selectedTenant.value.id}/entitlement`, {
            method: "DELETE",
            body: JSON.stringify({ keys }),
        });
        await refreshSelectedTenant();
        toast(message);
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
async function resetSmsOverrides() {
    await clearOverrides(
        ["sms_enabled", "sms_monthly_limit"],
        "Für SMS gilt wieder der Tarifstandard.",
    );
}
async function resetSelectedOverride() {
    if (!overrideForm.key) return;
    await clearOverrides(
        [overrideForm.key],
        "Für diese Leistung gilt wieder der Tarifstandard.",
    );
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
function askAuditCleanup() {
    const all = auditCleanupScope.value === "all";
    const days = all ? null : Number(auditCleanupScope.value);
    askConfirmation({
        title: all ? "Gesamtes Prüfprotokoll leeren?" : "Alte Protokolleinträge löschen?",
        message: all
            ? "Alle vorhandenen Prüfprotokolleinträge werden dauerhaft gelöscht. Ein neuer Eintrag dokumentiert anschließend diese Leerung."
            : `Alle Prüfprotokolleinträge, die älter als ${days} Tage sind, werden dauerhaft gelöscht.`,
        confirmLabel: all ? "Gesamtes Protokoll leeren" : `Älter als ${days} Tage löschen`,
        danger: true,
        run: async () => {
            const result = await api<any>("/control/audits", {
                method: "DELETE",
                body: JSON.stringify({
                    scope: all ? "all" : "older",
                    days,
                    confirmation: all ? "PRÜFPROTOKOLL LÖSCHEN" : null,
                }),
            });
            toast(`${result.deleted} Protokolleinträge wurden gelöscht.`);
        },
    });
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
async function selectBackupTenant() {
    filters.page = 1;
    await load();
}
async function tenantBackupAction(
    action: string,
    name?: string,
    rowTenantId?: number,
) {
    const tenant = (data.value?.tenants || []).find(
        (item: any) =>
            Number(item.id) ===
            Number(rowTenantId || backupTenantId.value),
    );
    if (!tenant) return;
    let confirmation: string | null = null;
    if (
        action === "create" &&
        !window.confirm(
            `Vollständiges Backup für „${tenant.name}“ erstellen? Enthalten sind auch Benutzer, Domains, Abrechnung, Zahlungen, Guthaben und Protokolle dieses Kunden.`,
        )
    )
        return;
    if (action === "restore") {
        confirmation = window.prompt(
            `„${tenant.name}“ wird VOLLSTÄNDIG auf diesen Stand zurückgesetzt: Inhalte, Dateien, Benutzer/Team, Slug, Domains, Status, Abrechnung, Zahlungen, Guthaben und Protokolle. Andere Kunden bleiben unverändert. Vorher wird automatisch ein Sicherheitsbackup erstellt.\n\nZur Bestätigung Kundencode eingeben: ${tenant.slug}`,
        );
        if (confirmation === null) return;
    }
    if (
        action === "delete" &&
        !confirm(`Backup ${name} von ${tenant.name} wirklich löschen?`)
    )
        return;
    const suffix = name ? `/${encodeURIComponent(name)}` : "";
    const actionSuffix = ["verify", "restore"].includes(action)
        ? `/${action}`
        : "";
    await submit(
        () =>
            api(
                `/control/backups/tenants/${tenant.id}${suffix}${actionSuffix}`,
                {
                    method: action === "delete" ? "DELETE" : "POST",
                    body:
                        action === "restore"
                            ? JSON.stringify({ confirmation })
                            : action === "create"
                              ? JSON.stringify({ confirmed: true })
                            : undefined,
                },
            ),
        action === "create"
            ? `Backup für ${tenant.name} wurde erstellt.`
            : action === "verify"
              ? "Backup ist vollständig."
              : action === "restore"
                ? `${tenant.name} wurde wiederhergestellt. Ein Sicherheitsbackup des vorherigen Stands wurde angelegt.`
                : "Backup wurde gelöscht.",
    );
}
const {
    editPage,
    closePageEditor,
    openPageTranslationResult,
    savePage,
    translatePage,
} = usePageTranslations({
    selectedPage,
    pageTranslationDrafts,
    contentLocale,
    modal,
    pageTranslation,
    section,
    router,
    data,
    busy,
    error,
    translating,
    load,
    toast,
});
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
const controlContext = {
    section,
    data,
    filters,
    pager,
    addLabels,
    metricLabels,
    openAdd,
    load,
    statusOptions,
    sortOptions,
    changePage,
    syncAllPlans,
    rows,
    me,
    openTenant,
    selectedAdministrator,
    administratorForm,
    openAdministrator,
    saveAdministrator,
    askDeleteAdministrator,
    openSubscription,
    tenantAccessClass,
    subscriptionAccessClass,
    subscriptionAccessLabel,
    activeEntitlements,
    editPlan,
    syncPlan,
    editTemplate,
    hasEditableTemplate,
    toggleCatalog,
    togglePhrase,
    editPage,
    backupAction,
    backupTenantId,
    selectBackupTenant,
    tenantBackupAction,
    auditCleanupScope,
    askAuditCleanup,
    smsEventLabels,
    smsStatusLabels,
    modal,
    selectedTenant,
    selectedSubscription,
    closeSubscription,
    subscriptionPaymentForm,
    subscriptionInvoiceForm,
    subscriptionStatusForm,
    saveSubscriptionPayment,
    saveSubscriptionInvoice,
    voidSubscriptionInvoice,
    prepareInvoicePayment,
    openInvoiceDocument,
    saveSubscriptionStatus,
    openPaymentReceipt,
    saveTenantDetails,
    busy,
    formatDate,
    impersonate,
    toggleUser,
    resetPassword,
    tenantAccessLabel,
    lookups,
    updateTenant,
    manualAccessDays,
    grantTenantAccess,
    domainAction,
    deleteDomain,
    customTenantDomain,
    addTenantDomain,
    overrideForm,
    saveOverride,
    smsOverrideForm,
    saveSmsOverrides,
    resetSmsOverrides,
    entitlementGroups,
    selectedOverrideDefinition,
    selectOverride,
    tenantHasOverride,
    resetSelectedOverride,
    deleteTenantPermanently,
    tenantForm,
    createTenant,
    editingPlanId,
    planImagePreview,
    planImageFile,
    selectPlanImage,
    clearPendingPlanImage,
    deletePlanImage,
    planForm,
    savePlan,
    translating,
    translatePlan,
    planLocales,
    planLocale,
    phraseForm,
    choosePhraseVariation,
    savePhrase,
    catalogKind,
    categoryForm,
    variationForm,
    templateForm,
    saveCatalog,
    selectedPage,
    closePageEditor,
    savePage,
    translatePage,
    pageTranslation,
    contentLocale,
    confirmAction,
    executeConfirmed,
    selectedAudit,
    error,
};
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
            <ControlTranslationJob :ctx="controlContext" />
            <p v-if="error" class="alert error">{{ error }}</p>
            <p v-if="noticeText" class="admin-toast">{{ noticeText }}</p>
            <div v-if="!data" class="loading">Wird geladen…</div>
            <template v-else>
                <ControlOverview
                    v-if="['dashboard', 'settings', 'stripe'].includes(section)"
                    :ctx="controlContext"
                />
                <ControlRegistry v-else :ctx="controlContext" />
                ></template
            >
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
        <ControlTenantDrawer v-if="selectedTenant" :ctx="controlContext" />
        <ControlAdministratorModal :ctx="controlContext" />
        <ControlSubscriptionModal :ctx="controlContext" />
        <ControlTenantCreateModal :ctx="controlContext" />
        <ControlPlanModal :ctx="controlContext" />
        <ControlCatalogModals :ctx="controlContext" />
        <ControlPageModal :ctx="controlContext" />
        <ControlConfirmationModals :ctx="controlContext" />
    </div>
</template>

<script setup lang="ts">
import "../../css/tenant-app.css";
import "../../css/tenant-steering.css";
import "../../css/tenant-brows.css";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ApiError, api } from "../api";
import { setLocale } from "../i18n";
import AppIcon from "../tenant-app/AppIcon.vue";
import BeforeAfterSlider from "../tenant-app/BeforeAfterSlider.vue";
import BookingFlow from "../tenant-app/BookingFlow.vue";
import { appCopy, type TenantLocale } from "../tenant-app/copy";
import RequestFlow from "../tenant-app/RequestFlow.vue";
import TenantActivity from "../tenant-app/TenantActivity.vue";
import TenantBottomNav from "../tenant-app/TenantBottomNav.vue";
import TenantContactsSheet from "../tenant-app/TenantContactsSheet.vue";
import TenantContactPopover from "../tenant-app/TenantContactPopover.vue";
import TenantDesktopAside from "../tenant-app/TenantDesktopAside.vue";
import TenantLogin from "../tenant-app/TenantLogin.vue";
import TenantInstallPrompt from "../tenant-app/TenantInstallPrompt.vue";
import TenantLanguagePrompt from "../tenant-app/TenantLanguagePrompt.vue";
import TenantMenuOverlay from "../tenant-app/TenantMenuOverlay.vue";
import TenantPushPrompt from "../tenant-app/TenantPushPrompt.vue";
import TenantPushNudge from "../tenant-app/TenantPushNudge.vue";
import TenantReviews from "../tenant-app/TenantReviews.vue";

const route = useRoute();
const router = useRouter();
const app = ref<any>(null);
const error = ref("");
const loading = ref(true);
const activity = ref<any>({ requests: [], appointments: [] });
const activityLoading = ref(false);
const selectedRequest = ref<any>(null);
const message = ref("");
const sending = ref(false);
const menuOpen = ref(false);
const contactOpen = ref(false);
const installOpen = ref(false);
const installPrompt = ref<any>(null);
const languageOpen = ref(false);
const pushPrompt = ref(false);
const pushBusy = ref(false);
const pushStatus = ref("");
type PushState =
    | "unsupported"
    | "install_required"
    | "default"
    | "subscribed"
    | "repair"
    | "denied";
const pushState = ref<PushState>("unsupported");
const pushNudge = ref(false);
const workFilter = ref<"all" | "before_after" | "finished" | "favorites">(
    "all",
);
const workFilterOpen = ref(false);
const mediaFilter = ref<"mixed" | "photos" | "videos">("mixed");
const portfolioPage = ref(1);
const portfolioPageSize = ref(6);
const lightbox = ref<{ src: string; alt: string } | null>(null);
const videoLightbox = ref<{ src: string; title: string } | null>(null);
const reviewOpen = ref(false);
const reviewBusy = ref(false);
const reviewNotice = ref("");
const reviewForm = ref({ rating: 5, body: "" });
const loginEmail = ref("");
const loginPassword = ref("");
const loginRemember = ref(true);
const loginBusy = ref(false);
const loginError = ref("");
const tokenKey = "lookdo-client:" + location.hostname;
const clientToken = ref(localStorage.getItem(tokenKey) || "");
const localeKey = "lookdo-client-locale:" + location.hostname;
const favoriteKey = "lookdo-portfolio-favorites:" + location.hostname;
const favoriteIds = ref<string[]>(loadFavoriteIds());
const savedLocale = localStorage.getItem(localeKey);
const hasSelectedLocale = ref(
    ["de", "en", "ru", "uk"].includes(savedLocale || ""),
);
const locale = ref<TenantLocale>(
    (hasSelectedLocale.value ? savedLocale : "de") as TenantLocale,
);
const copy = computed(() => appCopy(locale.value));
const hasMultipleLocales = computed(
    () => (app.value?.template?.locales || []).length > 1,
);
const installPlatform = computed<"ios" | "android" | "desktop">(() => {
    const agent = navigator.userAgent.toLowerCase();
    if (/iphone|ipad|ipod/.test(agent)) return "ios";
    if (/android/.test(agent)) return "android";
    return "desktop";
});
const appInstalled = computed(
    () =>
        window.matchMedia("(display-mode: standalone)").matches ||
        Boolean((navigator as any).standalone),
);
const isIos = computed(() => /iphone|ipad|ipod/i.test(navigator.userAgent));
const pushStateLabel = computed(
    () =>
        ({
            unsupported: copy.value.notificationUnsupported,
            install_required: copy.value.notificationInstallRequired,
            default: copy.value.notificationNotConfigured,
            subscribed: copy.value.notificationEnabled,
            repair: copy.value.notificationNeedsRepair,
            denied: copy.value.notificationBlocked,
        })[pushState.value],
);
const screen = computed(() => {
    const parts = route.path
        .split("/")
        .filter(Boolean)
        .filter((part) => !["de", "en", "ru", "uk"].includes(part));
    return parts[0] || "home";
});
const actionScreen = computed(() =>
    app.value?.template?.engine === "booking" ? "book" : "request",
);
const isSteering = computed(() => app.value?.template?.layout === "steering");
const isBrows = computed(() => app.value?.template?.layout === "brows");
const isBookPurchase = computed(
    () => app.value?.template?.variation_code === "purchase.books",
);
const headerLogo = computed(
    () =>
        app.value?.tenant?.logo ||
        app.value?.tenant?.branding?.horizontal_logo ||
        "/brand/lookdo-mark.webp",
);
const heroHeaderLabel = computed(() => app.value?.template?.hero?.eyebrow);
const bookHomeCopy = computed(() => {
    const values: Record<
        TenantLocale,
        {
            eyebrow: string;
            title: string;
            steps: Array<{ icon: string; title: string; text: string }>;
        }
    > = {
        de: {
            eyebrow: "SO FUNKTIONIERT ES",
            title: "Von den Fotos zur persönlichen Antwort",
            steps: [
                { icon: "camera", title: "Fotografieren", text: "Cover, ISBN und sichtbare Mängel." },
                { icon: "image", title: "Erkennen", text: "Ausgabe und Zustand werden vorgeprüft." },
                { icon: "message", title: "Antwort erhalten", text: "Der Käufer meldet sich persönlich." },
            ],
        },
        en: {
            eyebrow: "HOW IT WORKS",
            title: "From photos to a personal answer",
            steps: [
                { icon: "camera", title: "Take photos", text: "Cover, ISBN, and visible defects." },
                { icon: "image", title: "Identify", text: "Edition and condition are reviewed." },
                { icon: "message", title: "Get an answer", text: "The buyer replies personally." },
            ],
        },
        ru: {
            eyebrow: "КАК ЭТО РАБОТАЕТ",
            title: "От фотографий до личного ответа",
            steps: [
                { icon: "camera", title: "Сфотографируйте", text: "Обложку, ISBN и заметные дефекты." },
                { icon: "image", title: "Мы распознаем", text: "Издание и состояние книги." },
                { icon: "message", title: "Получите ответ", text: "Покупатель ответит лично." },
            ],
        },
        uk: {
            eyebrow: "ЯК ЦЕ ПРАЦЮЄ",
            title: "Від фотографій до особистої відповіді",
            steps: [
                { icon: "camera", title: "Сфотографуйте", text: "Обкладинку, ISBN і помітні дефекти." },
                { icon: "image", title: "Ми розпізнаємо", text: "Видання та стан книги." },
                { icon: "message", title: "Отримайте відповідь", text: "Покупець відповість особисто." },
            ],
        },
    };

    return values[locale.value];
});
const theme = computed(() => ({
    "--ta-primary": isSteering.value
        ? "#e2ad55"
        : app.value?.tenant?.colors?.primary ||
          app.value?.template?.theme?.primary ||
          "#ff6b00",
    "--ta-secondary": isSteering.value
        ? "#07090b"
        : app.value?.tenant?.colors?.secondary ||
          app.value?.template?.theme?.secondary ||
          "#111318",
    "--ta-template-surface": app.value?.template?.theme?.surface || "#fff",
    "--ta-template-text": app.value?.template?.theme?.text || "#111318",
}));
const homeBlocks = computed<any[]>(() => {
    const screens = app.value?.template?.screens || [];
    return screens.find((item: any) => item.key === "home")?.blocks || [];
});
function homeBlock(types: string | string[]) {
    const accepted = Array.isArray(types) ? types : [types];
    return homeBlocks.value.find((item: any) => accepted.includes(item.type));
}
function homeBlockVisible(types: string | string[]) {
    const accepted = Array.isArray(types) ? types : [types];
    if (
        app.value?.template?.capabilities?.portfolio === false &&
        accepted.some((type) => ["gallery", "before-after", "videos"].includes(type))
    )
        return false;
    const block = homeBlock(types);
    if (block) return block.enabled !== false;
    return !app.value?.template?.capabilities?.ui_builder_enabled;
}
function homeBlockStyle(types: string | string[], fallback: number) {
    const block = homeBlock(types);
    const index = block ? homeBlocks.value.indexOf(block) : -1;
    return { order: index >= 0 ? index : 100 + fallback };
}
function homeBlockTitle(types: string | string[], fallback: string) {
    return homeBlock(types)?.title || fallback;
}
const address = computed(() =>
    [
        app.value?.tenant?.contact?.street,
        [
            app.value?.tenant?.contact?.postal_code,
            app.value?.tenant?.contact?.city,
        ]
            .filter(Boolean)
            .join(" "),
    ]
        .filter(Boolean)
        .join(", "),
);
const averageRating = computed(() => {
    const rows = app.value?.reviews || [];
    return rows.length
        ? (
              rows.reduce(
                  (sum: number, item: any) => sum + Number(item.rating || 0),
                  0,
              ) / rows.length
          ).toFixed(1)
        : "—";
});
const filteredPortfolio = computed(() => {
    let rows = app.value?.portfolio || [];
    if (workFilter.value === "before_after")
        rows = rows.filter(
            (item: any) => item.before_image && item.after_image,
        );
    if (workFilter.value === "finished")
        rows = rows.filter(
            (item: any) => !(item.before_image && item.after_image),
        );
    if (workFilter.value === "favorites")
        rows = rows.filter((item: any) =>
            favoriteIds.value.includes(String(item.id)),
        );
    if (mediaFilter.value === "photos")
        return rows.filter((item: any) => !item.video);
    if (mediaFilter.value === "videos")
        return rows.filter((item: any) => !!item.video);
    return mixPortfolio(rows, portfolioPageSize.value);
});
const portfolioPageCount = computed(() =>
    Math.max(
        1,
        Math.ceil(filteredPortfolio.value.length / portfolioPageSize.value),
    ),
);
const paginatedPortfolio = computed(() => {
    const start = (portfolioPage.value - 1) * portfolioPageSize.value;
    return filteredPortfolio.value.slice(
        start,
        start + portfolioPageSize.value,
    );
});
const mediaLabels = computed(() => ({
    photos: { de: "Fotos", en: "Photos", ru: "Фото", uk: "Фото" }[locale.value],
    videos: { de: "Videos", en: "Videos", ru: "Видео", uk: "Відео" }[
        locale.value
    ],
}));
const portfolioVideos = computed(() =>
    (app.value?.portfolio || []).filter((item: any) => item.video),
);
const portfolioImages = computed(() =>
    (app.value?.portfolio || []).filter(
        (item: any) =>
            !item.video &&
            (item.image || item.before_image || item.after_image),
    ),
);
const portfolioLabels = computed(() => ({
    all: {
        de: "Alle Arbeiten",
        en: "All work",
        ru: "Все работы",
        uk: "Усі роботи",
    }[locale.value],
    finished: {
        de: "Fertige Arbeiten",
        en: "Finished work",
        ru: "Готовые работы",
        uk: "Готові роботи",
    }[locale.value],
    favorites: {
        de: "Favoriten",
        en: "Favorites",
        ru: "Избранное",
        uk: "Обране",
    }[locale.value],
    filters: { de: "Filter", en: "Filter", ru: "Фильтр", uk: "Фільтр" }[
        locale.value
    ],
    perPage: {
        de: "Pro Seite",
        en: "Per page",
        ru: "На странице",
        uk: "На сторінці",
    }[locale.value],
    close: { de: "Schließen", en: "Close", ru: "Закрыть", uk: "Закрити" }[
        locale.value
    ],
    lightboxHint: {
        de: "Außerhalb des Bildes oder auf × tippen, um zu schließen",
        en: "Tap outside the image or × to close",
        ru: "Нажмите вне фотографии или на ×, чтобы закрыть",
        uk: "Натисніть поза фотографією або на ×, щоб закрити",
    }[locale.value],
}));
const hasBeforeAfterPortfolio = computed(() =>
    (app.value?.portfolio || []).some(
        (item: any) => item.before_image && item.after_image,
    ),
);

function mixPortfolio(rows: any[], pageSize: number) {
    const photos = rows.filter((item: any) => !item.video);
    const videos = rows.filter((item: any) => !!item.video);
    if (!photos.length || !videos.length) return rows;

    const result: any[] = [];
    const photoCount = Math.max(1, Math.round((pageSize * 2) / 3));
    const videoCount = Math.max(1, pageSize - photoCount);

    while (photos.length || videos.length) {
        const page = photos
            .splice(0, photoCount)
            .concat(videos.splice(0, videoCount));
        while (page.length < pageSize && (photos.length || videos.length)) {
            page.push((photos.length ? photos : videos).shift());
        }
        result.push(...page);
    }

    return result;
}
const serviceDetailLabels = computed(() => ({
    inclusions: {
        de: "Was ist enthalten?",
        en: "What's included?",
        ru: "Что входит?",
        uk: "Що входить?",
    }[locale.value],
    result: {
        de: "Ergebnis",
        en: "Result",
        ru: "Результат",
        uk: "Результат",
    }[locale.value],
}));
const videoLabels = computed(() => ({
    heading: { de: "Videos", en: "Videos", ru: "Видео", uk: "Відео" }[
        locale.value
    ],
    close: { de: "Schließen", en: "Close", ru: "Закрыть", uk: "Закрити" }[
        locale.value
    ],
}));
const navItems = computed(() => {
    const fallback = isBrows.value
        ? ["home", "services", "book", "activity", "reviews"]
        : ["home", "works", "action", "activity", "reviews"];
    const configured = app.value?.template?.navigation?.length
        ? app.value.template.navigation
        : fallback;
    const catalog: Record<string, any> = {
        home: { key: "home", icon: "home", label: copy.value.home },
        works: { key: "works", icon: "works", label: copy.value.works },
        services: { key: "services", icon: "works", label: copy.value.servicesNav },
        action: { key: actionScreen.value, icon: "camera", label: copy.value.action, central: true },
        request: { key: "request", icon: "camera", label: copy.value.action, central: true },
        book: { key: "book", icon: "measure", label: copy.value.book, central: true },
        activity: { key: "activity", icon: "message", label: copy.value.activity },
        reviews: { key: "reviews", icon: "star", label: copy.value.reviews },
        contacts: { key: "contacts", icon: "phone", label: copy.value.contacts },
    };
    const seen = new Set<string>();
    return configured
        .map((key: string) => catalog[key])
        .filter((item: any) => item && !seen.has(item.key) && seen.add(item.key));
});
const contactName = computed(
    () => app.value?.tenant?.contact?.name || app.value?.tenant?.name,
);
const hasContactMethods = computed(() =>
    [
        "phone",
        "whatsapp_url",
        "max_url",
        "telegram_url",
        "viber_url",
        "vk_url",
        "instagram_url",
        "facebook_url",
        "email",
        "website_url",
    ].some((key) => Boolean(app.value?.tenant?.contact?.[key])),
);
const rescheduleAppointment = ref<any>(null);

function loadFavoriteIds(): string[] {
    try {
        const value = JSON.parse(localStorage.getItem(favoriteKey) || "[]");
        return Array.isArray(value) ? value.map(String) : [];
    } catch {
        return [];
    }
}
function isFavorite(id: unknown) {
    return favoriteIds.value.includes(String(id));
}
function toggleFavorite(id: unknown) {
    const value = String(id);
    favoriteIds.value = isFavorite(value)
        ? favoriteIds.value.filter((item) => item !== value)
        : [...favoriteIds.value, value];
    localStorage.setItem(favoriteKey, JSON.stringify(favoriteIds.value));
}
function captureInstallPrompt(event: Event) {
    event.preventDefault();
    installPrompt.value = event;
}
function showInstall() {
    menuOpen.value = false;
    installOpen.value = true;
}
async function installApp() {
    if (!installPrompt.value) return;
    await installPrompt.value.prompt();
    await installPrompt.value.userChoice;
    installPrompt.value = null;
    installOpen.value = false;
}
function selectWorkFilter(value: typeof workFilter.value) {
    workFilter.value = value;
    workFilterOpen.value = false;
}
function selectMediaFilter(value: typeof mediaFilter.value) {
    mediaFilter.value = value;
    workFilter.value = "all";
    workFilterOpen.value = false;
}
function selectPortfolioPageSize(value: number) {
    portfolioPageSize.value = value;
    portfolioPage.value = 1;
    workFilterOpen.value = false;
}
watch(
    [workFilter, mediaFilter, portfolioPageSize],
    () => (portfolioPage.value = 1),
);
function openLightbox(src: string, alt: string) {
    lightbox.value = { src, alt };
}
function handleKeydown(event: KeyboardEvent) {
    if (event.key !== "Escape") return;
    lightbox.value = null;
    installOpen.value = false;
    menuOpen.value = false;
}

function tenantLocale(value: unknown): TenantLocale | null {
    return typeof value === "string" && ["de", "en", "ru", "uk"].includes(value)
        ? (value as TenantLocale)
        : null;
}
function applyTenantLocale(value: unknown) {
    const next = tenantLocale(value);
    if (!next) return;
    locale.value = next;
    setLocale(next);
}
function screenAllowed(target: string) {
    if (target === "works")
        return app.value?.template?.capabilities?.portfolio !== false;
    if (target === "reviews")
        return app.value?.template?.capabilities?.reviews !== false;
    return true;
}
function go(target: string) {
    menuOpen.value = false;
    if (!screenAllowed(target)) target = "home";
    router.push(target === "home" ? "/" : "/" + target);
}
async function load() {
    loading.value = true;
    error.value = "";
    try {
        const headers: any = {};
        if (hasSelectedLocale.value) headers["X-Locale"] = locale.value;
        if (clientToken.value)
            headers["X-Lookdo-Client-Token"] = clientToken.value;
        app.value = await api("/tenant-app/bootstrap", { headers });
        if (!screenAllowed(screen.value)) await router.replace("/");
        applyTenantLocale(app.value.tenant.locale || "de");
        if (
            !hasSelectedLocale.value &&
            (app.value.template.locales || []).length > 1
        )
            languageOpen.value = true;
        if (screen.value === "activity") await loadActivity();
        await refreshPushState();
        if (pushState.value === "subscribed")
            await syncExistingPushSubscription();
        if (shouldNudgePush()) {
            pushNudge.value = true;
            localStorage.setItem(
                "lookdo-push-nudge:" + location.hostname,
                localDayKey(),
            );
        }
    } catch (e: any) {
        if (e instanceof ApiError) applyTenantLocale(e.payload.locale);
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}
function shouldNudgePush() {
    const today = localDayKey();
    return Boolean(
        hasSelectedLocale.value &&
        ["default", "install_required"].includes(pushState.value) &&
        localStorage.getItem("lookdo-push-nudge:" + location.hostname) !==
            today,
    );
}
function localDayKey() {
    const now = new Date();
    return [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, "0"),
        String(now.getDate()).padStart(2, "0"),
    ].join("-");
}
async function refreshPushState() {
    if (!app.value?.push?.enabled) {
        pushState.value = "unsupported";
        return;
    }
    if (isIos.value && !appInstalled.value) {
        pushState.value = "install_required";
        return;
    }
    if (
        !("Notification" in window) ||
        !("serviceWorker" in navigator) ||
        !("PushManager" in window)
    ) {
        pushState.value = "unsupported";
        return;
    }
    if (Notification.permission === "denied") {
        pushState.value = "denied";
        return;
    }
    if (Notification.permission === "default") {
        pushState.value = "default";
        return;
    }
    try {
        const registration = await navigator.serviceWorker.ready;
        pushState.value = (await registration.pushManager.getSubscription())
            ? "subscribed"
            : "repair";
    } catch {
        pushState.value = "repair";
    }
}
async function syncExistingPushSubscription() {
    if (!app.value?.push?.enabled || !("serviceWorker" in navigator)) return;
    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (!subscription) return;
        const value = subscription.toJSON();
        await api("/tenant-app/push-subscriptions", {
            method: "POST",
            headers: clientToken.value
                ? { "X-Lookdo-Client-Token": clientToken.value }
                : {},
            body: JSON.stringify({
                endpoint: value.endpoint,
                keys: value.keys,
            }),
        });
    } catch {
        // A stale browser subscription must never block the application itself.
    }
}
async function loadActivity() {
    if (!clientToken.value) {
        activity.value = { requests: [], appointments: [] };
        return;
    }
    activityLoading.value = true;
    try {
        activity.value = await api("/tenant-app/activity", {
            headers: { "X-Lookdo-Client-Token": clientToken.value },
        });
        if (selectedRequest.value)
            selectedRequest.value =
                activity.value.requests.find(
                    (item: any) => item.id === selectedRequest.value.id,
                ) || null;
    } catch (e: any) {
        error.value = e.message;
    } finally {
        activityLoading.value = false;
    }
}
function flowSuccess(payload: any) {
    if (payload.token) {
        clientToken.value = payload.token;
        localStorage.setItem(tokenKey, payload.token);
    }
    void refreshPushState();
    void syncExistingPushSubscription();
    loadActivity();
}
async function cancelAppointment(item: any) {
    if (!confirm(copy.value.cancelConfirm)) return;
    try {
        await api("/tenant-app/appointments/" + item.id, {
            method: "DELETE",
            headers: { "X-Lookdo-Client-Token": clientToken.value },
        });
        await loadActivity();
    } catch (e: any) {
        error.value = e.message;
    }
}
async function changeLocale(value: string) {
    locale.value = value as TenantLocale;
    hasSelectedLocale.value = true;
    localStorage.setItem(localeKey, locale.value);
    setLocale(locale.value);
    languageOpen.value = false;
    await load();
}
async function sendMessage() {
    if (!message.value.trim() || !selectedRequest.value) return;
    sending.value = true;
    try {
        await api(
            "/tenant-app/requests/" + selectedRequest.value.id + "/messages",
            {
                method: "POST",
                headers: { "X-Lookdo-Client-Token": clientToken.value },
                body: JSON.stringify({ body: message.value }),
            },
        );
        message.value = "";
        await loadActivity();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        sending.value = false;
    }
}
async function submitReview() {
    if (!app.value?.session?.known || !reviewForm.value.body.trim()) return;
    reviewBusy.value = true;
    reviewNotice.value = "";
    try {
        const result: any = await api("/tenant-app/reviews", {
            method: "POST",
            headers: { "X-Lookdo-Client-Token": clientToken.value },
            body: JSON.stringify(reviewForm.value),
        });
        reviewForm.value = { rating: 5, body: "" };
        reviewNotice.value = result.message || copy.value.reviewThanks;
        reviewOpen.value = false;
        if (app.value?.session?.review) {
            app.value.session.review.can_submit = false;
            app.value.session.review.submitted = true;
            app.value.session.review.request_id = null;
        }
    } catch (e: any) {
        reviewNotice.value = e.message;
    } finally {
        reviewBusy.value = false;
    }
}
function statusLabel(status: string) {
    if (status === "cancelled") return copy.value.cancelled;
    if (status === "no_show") return copy.value.noShow;
    if (["completed", "done", "closed"].includes(status))
        return copy.value.statusDone;
    if (["pending", "in_progress"].includes(status))
        return copy.value.statusPending;
    return copy.value.statusNew;
}
async function share() {
    const data = {
        title: app.value.tenant.name,
        text: app.value.template.hero.text,
        url: location.origin,
    };
    if (navigator.share) await navigator.share(data);
    else await navigator.clipboard.writeText(data.url);
}
function applicationServerKey(value: string): Uint8Array {
    const padding = "=".repeat((4 - (value.length % 4)) % 4);
    const raw = atob((value + padding).replace(/-/g, "+").replace(/_/g, "/"));
    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}
async function enablePush() {
    pushBusy.value = true;
    pushStatus.value = "";
    try {
        const permission = await Notification.requestPermission();
        if (permission !== "granted") {
            pushStatus.value = copy.value.notificationDenied;
            return;
        }
        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();
        if (!subscription)
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey(
                    app.value.push.public_key,
                ) as BufferSource,
            });
        const value = subscription.toJSON();
        await api("/tenant-app/push-subscriptions", {
            method: "POST",
            headers: clientToken.value
                ? { "X-Lookdo-Client-Token": clientToken.value }
                : {},
            body: JSON.stringify({
                endpoint: value.endpoint,
                keys: value.keys,
            }),
        });
        pushStatus.value = copy.value.notificationEnabled;
        pushState.value = "subscribed";
        window.setTimeout(() => (pushPrompt.value = false), 700);
    } catch (e: any) {
        pushStatus.value = /applicationServerKey|P-256|public key/i.test(
            String(e?.message || ""),
        )
            ? copy.value.notificationConfigurationError
            : copy.value.notificationDenied;
        await refreshPushState();
    } finally {
        pushBusy.value = false;
    }
}
function dismissPush() {
    pushPrompt.value = false;
}
function dismissPushNudge() {
    localStorage.setItem(
        "lookdo-push-nudge:" + location.hostname,
        localDayKey(),
    );
    pushNudge.value = false;
}
async function openPushSettings() {
    menuOpen.value = false;
    pushNudge.value = false;
    await refreshPushState();
    if (pushState.value === "install_required") {
        showInstall();
        return;
    }
    pushStatus.value = "";
    pushPrompt.value = true;
}
async function handleVisibilityChange() {
    if (document.visibilityState !== "visible") return;
    await refreshPushState();
    if (shouldNudgePush()) {
        pushNudge.value = true;
        localStorage.setItem(
            "lookdo-push-nudge:" + location.hostname,
            localDayKey(),
        );
    }
}
async function login() {
    loginBusy.value = true;
    loginError.value = "";
    try {
        const result = await api("/login", {
            method: "POST",
            body: JSON.stringify({
                email: loginEmail.value,
                password: loginPassword.value,
                remember: loginRemember.value,
            }),
        });
        const labels = location.hostname.split(".");
        const platform =
            labels.length > 2 ? labels.slice(-2).join(".") : location.hostname;
        location.href =
            location.protocol +
            "//" +
            platform +
            (result.user?.is_super_admin ? "/control" : "/app");
    } catch (e: any) {
        loginError.value = e.message || copy.value.loginError;
    } finally {
        loginBusy.value = false;
    }
}
watch(screen, async (value) => {
    selectedRequest.value = null;
    if (value === "activity") await loadActivity();
});
onMounted(() => {
    window.addEventListener("keydown", handleKeydown);
    window.addEventListener("beforeinstallprompt", captureInstallPrompt);
    document.addEventListener("visibilitychange", handleVisibilityChange);
    load();
});
onBeforeUnmount(() => {
    window.removeEventListener("keydown", handleKeydown);
    window.removeEventListener("beforeinstallprompt", captureInstallPrompt);
    document.removeEventListener("visibilitychange", handleVisibilityChange);
});
const loginContext = {
    app,
    copy,
    locale,
    contactName,
    loginEmail,
    loginPassword,
    loginRemember,
    loginError,
    loginBusy,
    login,
};
</script>

<template>
    <div
        class="tenant-app-viewport"
        :class="{
            'theme-steering': isSteering,
            'theme-brows': isBrows,
            'theme-books': isBookPurchase,
        }"
        :style="theme"
    >
        <div v-if="loading" class="ta-splash">
            <img :src="'/brand/lookdo-mark.webp'" alt="" /><span>LOOKDO</span>
        </div>
        <div v-else-if="error && !app" class="ta-unavailable">
            <img :src="'/brand/lookdo-mark.webp'" alt="" />
            <h1>{{ copy.unavailable }}</h1>
            <p>{{ error }}</p>
            <button class="ta-primary" @click="load">{{ copy.retry }}</button>
        </div>
        <div v-else-if="app" class="tenant-app-desktop">
            <main class="tenant-app-shell">
                <BookingFlow
                    v-if="rescheduleAppointment"
                    :app="app"
                    :copy="copy"
                    :locale="locale"
                    :token="clientToken"
                    :push-state="pushState"
                    :appointment="rescheduleAppointment"
                    @close="rescheduleAppointment = null"
                    @success="flowSuccess"
                    @enable-push="openPushSettings"
                />
                <RequestFlow
                    v-else-if="screen === 'request'"
                    :app="app"
                    :copy="copy"
                    :locale="locale"
                    :token="clientToken"
                    @close="go('home')"
                    @success="flowSuccess"
                />
                <BookingFlow
                    v-else-if="screen === 'book'"
                    :app="app"
                    :copy="copy"
                    :locale="locale"
                    :token="clientToken"
                    :push-state="pushState"
                    @close="go('home')"
                    @success="flowSuccess"
                    @enable-push="openPushSettings"
                />
                <TenantLogin
                    v-else-if="screen === 'login'"
                    :ctx="loginContext"
                    @home="go('home')"
                />
                <template v-else>
                    <div class="ta-scroll-area">
                        <section
                            v-if="screen === 'home' && isBrows"
                            class="ta-home-screen ta-brows-home"
                        >
                            <header class="ta-brows-header">
                                <button class="ta-brand" @click="go('home')">
                                    <img
                                        class="ta-public-header-logo"
                                        :src="headerLogo"
                                        :alt="app.tenant.name"
                                    /><span>{{ app.tenant.name }}</span>
                                </button>
                                <div>
                                    <button
                                        v-if="hasMultipleLocales"
                                        class="ta-header-action ta-language-trigger"
                                        @click="languageOpen = true"
                                    >
                                        {{ locale.toUpperCase() }}
                                    </button>
                                    <button
                                        v-if="hasContactMethods"
                                        class="ta-header-action ta-contact-trigger"
                                        :aria-label="copy.contacts"
                                        @click="contactOpen = !contactOpen"
                                    >
                                        <AppIcon name="phone" />
                                    </button>
                                    <button
                                        class="ta-header-action"
                                        @click="
                                            contactOpen = false;
                                            menuOpen = true;
                                        "
                                    >
                                        <AppIcon name="menu" />
                                    </button>
                                    <TenantContactPopover
                                        v-if="contactOpen"
                                        :contact="app.tenant.contact"
                                        :copy="copy"
                                        @close="contactOpen = false"
                                    />
                                </div>
                            </header>
                            <article
                                v-if="homeBlockVisible('hero')"
                                class="ta-brows-hero"
                                :style="homeBlockStyle('hero', 0)"
                            >
                                <img
                                    :src="
                                        app.template.hero.image ||
                                        '/brand/service-brows.webp'
                                    "
                                    :alt="app.template.hero.title"
                                />
                                <div></div>
                                <section>
                                    <small>{{
                                        app.template.hero.eyebrow
                                    }}</small>
                                    <h1>
                                        {{ app.template.hero.title }}
                                    </h1>
                                    <p>{{ app.template.hero.text }}</p>
                                    <button
                                        class="ta-primary ta-hero-action"
                                        @click="go('book')"
                                    >
                                        <AppIcon name="calendar" />{{
                                            app.template.hero.action
                                        }}
                                    </button>
                                </section>
                            </article>
                            <section
                                v-if="homeBlockVisible(['gallery', 'before-after'])"
                                class="ta-section ta-featured"
                                :style="homeBlockStyle(['gallery', 'before-after'], 1)"
                            >
                                <div class="ta-section-head">
                                    <h2>{{ homeBlockTitle(['gallery', 'before-after'], copy.works) }}</h2>
                                    <button @click="go('works')">
                                        {{ copy.all }}
                                        <AppIcon name="arrow" :size="17" />
                                    </button>
                                </div>
                                <div
                                    v-if="portfolioImages.length"
                                    class="ta-work-strip"
                                >
                                    <button
                                        v-for="item in portfolioImages.slice(
                                            0,
                                            4,
                                        )"
                                        :key="item.id"
                                        @click="
                                            openLightbox(
                                                item.image ||
                                                    item.after_image ||
                                                    item.before_image,
                                                item.title,
                                            )
                                        "
                                    >
                                        <BeforeAfterSlider
                                            v-if="
                                                item.before_image &&
                                                item.after_image
                                            "
                                            :before="item.before_image"
                                            :after="item.after_image"
                                            :before-label="copy.before"
                                            :after-label="copy.after"
                                            :alt="item.title"
                                        /><img
                                            v-else
                                            :src="
                                                item.image ||
                                                item.after_image ||
                                                item.before_image
                                            "
                                            :alt="item.title"
                                        /><span>{{ item.title }}</span>
                                    </button>
                                </div>
                                <div v-else class="ta-section-empty">
                                    <AppIcon name="image" />
                                    <p>{{ copy.noActivity }}</p>
                                </div>
                            </section>
                            <section
                                v-if="app.template.trust.length && homeBlockVisible('trust')"
                                class="ta-brows-trust"
                                :style="homeBlockStyle('trust', 2)"
                            >
                                <article
                                    v-for="item in app.template.trust"
                                    :key="item.label"
                                >
                                    <span><AppIcon :name="item.icon" /></span>
                                    <p>{{ item.label }}</p>
                                </article>
                            </section>
                            <section
                                v-if="isBookPurchase"
                                class="ta-book-process"
                                :style="{ order: 103 }"
                            >
                                <header>
                                    <small>{{ bookHomeCopy.eyebrow }}</small>
                                    <h2>{{ bookHomeCopy.title }}</h2>
                                </header>
                                <div>
                                    <article
                                        v-for="(step, index) in bookHomeCopy.steps"
                                        :key="step.title"
                                    >
                                        <span><AppIcon :name="step.icon" /></span>
                                        <small>0{{ index + 1 }}</small>
                                        <h3>{{ step.title }}</h3>
                                        <p>{{ step.text }}</p>
                                    </article>
                                </div>
                            </section>
                            <section
                                v-if="portfolioVideos.length && homeBlockVisible('videos')"
                                class="ta-section ta-video-works"
                                :style="homeBlockStyle('videos', 3)"
                            >
                                <div class="ta-section-head">
                                    <h2>{{ homeBlockTitle('videos', videoLabels.heading) }}</h2>
                                    <button @click="go('works')">
                                        {{ copy.all }}
                                        <AppIcon name="arrow" :size="17" />
                                    </button>
                                </div>
                                <div class="ta-video-work-grid">
                                    <button
                                        v-for="item in portfolioVideos.slice(
                                            0,
                                            4,
                                        )"
                                        :key="item.id"
                                        type="button"
                                        @click="
                                            videoLightbox = {
                                                src: item.video,
                                                title: item.title,
                                            }
                                        "
                                    >
                                        <video
                                            :src="item.video"
                                            playsinline
                                            preload="metadata"
                                        ></video>
                                        <h3>{{ item.title }}</h3>
                                    </button>
                                </div>
                            </section>
                        </section>

                        <section
                            v-else-if="screen === 'home'"
                            class="ta-home-screen"
                        >
                            <article
                                v-if="homeBlockVisible('hero')"
                                class="ta-hero"
                                :class="{ 'ta-hero-books': isBookPurchase }"
                                :style="homeBlockStyle('hero', 0)"
                            >
                                <img
                                    class="ta-hero-image"
                                    :src="
                                        app.template.hero.image ||
                                        '/brand/steering-wheel-placeholder.svg'
                                    "
                                    :alt="app.template.hero.eyebrow"
                                />
                                <div class="ta-hero-shade"></div>
                                <header class="ta-hero-header">
                                    <button
                                        class="ta-brand"
                                        @click="go('home')"
                                    >
                                        <img
                                            class="ta-public-header-logo"
                                            :src="headerLogo"
                                            :alt="app.tenant.name"
                                        /><span>{{ app.tenant.name }}</span>
                                    </button>
                                    <span class="ta-service-name">{{
                                        heroHeaderLabel
                                    }}</span>
                                    <div>
                                        <button
                                            v-if="hasMultipleLocales"
                                            class="ta-header-action ta-language-trigger"
                                            @click="languageOpen = true"
                                        >
                                            {{ locale.toUpperCase() }}
                                        </button>
                                        <button
                                            v-if="hasContactMethods"
                                            class="ta-header-action ta-contact-trigger"
                                            :aria-label="copy.contacts"
                                            @click="contactOpen = !contactOpen"
                                        >
                                            <AppIcon name="phone" />
                                        </button>
                                        <button
                                            class="ta-header-action"
                                            @click="
                                                contactOpen = false;
                                                menuOpen = true;
                                            "
                                        >
                                            <AppIcon name="menu" />
                                        </button>
                                        <TenantContactPopover
                                            v-if="contactOpen"
                                            :contact="app.tenant.contact"
                                            :copy="copy"
                                            @close="contactOpen = false"
                                        />
                                    </div>
                                </header>
                                <div class="ta-hero-content">
                                    <small class="ta-hero-eyebrow">{{
                                        app.template.hero.eyebrow
                                    }}</small>
                                    <h1>{{ app.template.hero.title }}</h1>
                                    <p>{{ app.template.hero.text }}</p>
                                    <button
                                        class="ta-gold-button ta-hero-action"
                                        @click="go(actionScreen)"
                                    >
                                        <AppIcon
                                            :name="
                                                app.template.engine ===
                                                'booking'
                                                    ? 'calendar'
                                                    : 'camera'
                                            "
                                        />{{ app.template.hero.action }}
                                    </button>
                                </div>
                            </article>
                            <section
                                v-if="homeBlockVisible(['gallery', 'before-after'])"
                                class="ta-section ta-featured"
                                :style="homeBlockStyle(['gallery', 'before-after'], 1)"
                            >
                                <div class="ta-section-head">
                                    <h2>{{ homeBlockTitle(['gallery', 'before-after'], copy.featured) }}</h2>
                                    <button @click="go('works')">
                                        {{ copy.all }}
                                        <AppIcon name="arrow" :size="17" />
                                    </button>
                                </div>
                                <div
                                    v-if="portfolioImages.length"
                                    class="ta-work-strip"
                                >
                                    <button
                                        v-for="item in portfolioImages.slice(
                                            0,
                                            4,
                                        )"
                                        :key="item.id"
                                        @click="
                                            openLightbox(
                                                item.image ||
                                                    item.after_image ||
                                                    item.before_image,
                                                item.title,
                                            )
                                        "
                                    >
                                        <BeforeAfterSlider
                                            v-if="
                                                item.before_image &&
                                                item.after_image
                                            "
                                            :before="item.before_image"
                                            :after="item.after_image"
                                            :before-label="copy.before"
                                            :after-label="copy.after"
                                            :alt="item.title"
                                        /><img
                                            v-else
                                            :src="
                                                item.image ||
                                                item.after_image ||
                                                item.before_image
                                            "
                                            :alt="item.title"
                                        /><span>{{ item.title }}</span>
                                    </button>
                                </div>
                                <div v-else class="ta-section-empty">
                                    <AppIcon name="image" />
                                    <p>{{ copy.noActivity }}</p>
                                </div>
                            </section>
                            <section
                                v-if="app.template.trust.length && homeBlockVisible('trust')"
                                class="ta-trust"
                                :style="homeBlockStyle('trust', 2)"
                            >
                                <article
                                    v-for="item in app.template.trust"
                                    :key="item.label"
                                >
                                    <span><AppIcon :name="item.icon" /></span>
                                    <p>{{ item.label }}</p>
                                </article>
                            </section>
                            <section
                                v-if="portfolioVideos.length && homeBlockVisible('videos')"
                                class="ta-section ta-video-works"
                                :style="homeBlockStyle('videos', 3)"
                            >
                                <div class="ta-section-head">
                                    <h2>{{ homeBlockTitle('videos', videoLabels.heading) }}</h2>
                                    <button @click="go('works')">
                                        {{ copy.all }}
                                        <AppIcon name="arrow" :size="17" />
                                    </button>
                                </div>
                                <div class="ta-video-work-grid">
                                    <button
                                        v-for="item in portfolioVideos.slice(
                                            0,
                                            4,
                                        )"
                                        :key="item.id"
                                        type="button"
                                        @click="
                                            videoLightbox = {
                                                src: item.video,
                                                title: item.title,
                                            }
                                        "
                                    >
                                        <video
                                            :src="item.video"
                                            playsinline
                                            preload="metadata"
                                        ></video>
                                        <h3>{{ item.title }}</h3>
                                    </button>
                                </div>
                            </section>
                        </section>

                        <section
                            v-else-if="screen === 'services'"
                            class="ta-page ta-brows-services-page"
                        >
                            <header class="ta-simple-header">
                                <button @click="go('home')">
                                    <AppIcon name="back" />{{ copy.back }}
                                </button>
                                <h1>{{ copy.servicesNav }}</h1>
                                <button
                                    class="ta-icon-button"
                                    @click="menuOpen = true"
                                >
                                    <AppIcon name="menu" />
                                </button>
                            </header>
                            <p class="ta-centered">
                                {{ app.template.hero.text }}
                            </p>
                            <div class="ta-brows-service-catalog">
                                <article
                                    v-for="service in app.services"
                                    :key="service.id"
                                >
                                    <img
                                        v-if="service.image"
                                        :src="service.image"
                                        :alt="service.name"
                                    />
                                    <div>
                                        <h2>{{ service.name }}</h2>
                                        <p v-if="service.description">
                                            {{ service.description }}
                                        </p>
                                        <details v-if="service.inclusions">
                                            <summary>
                                                {{
                                                    serviceDetailLabels.inclusions
                                                }}
                                            </summary>
                                            <p>{{ service.inclusions }}</p>
                                        </details>
                                        <details v-if="service.result">
                                            <summary>
                                                {{ serviceDetailLabels.result }}
                                            </summary>
                                            <p>{{ service.result }}</p>
                                        </details>
                                    </div>
                                    <button @click="go('book')">
                                        {{ copy.bookNow
                                        }}<AppIcon name="arrow" />
                                    </button>
                                </article>
                            </div>
                        </section>

                        <section
                            v-else-if="screen === 'works'"
                            class="ta-page ta-works-page"
                        >
                            <header class="ta-page-header">
                                <button @click="go('home')">
                                    <img
                                        :src="
                                            app.tenant.logo ||
                                            '/brand/lookdo-mark.webp'
                                        "
                                        alt=""
                                    /><span>{{ app.tenant.name }}</span>
                                </button>
                                <div>
                                    <button
                                        v-if="hasContactMethods"
                                        class="ta-contact-trigger"
                                        :aria-label="copy.contacts"
                                        @click="contactOpen = !contactOpen"
                                    >
                                        <AppIcon name="phone" />
                                    </button>
                                    <TenantContactPopover
                                        v-if="contactOpen"
                                        :contact="app.tenant.contact"
                                        :copy="copy"
                                        @close="contactOpen = false"
                                    />
                                </div>
                            </header>
                            <div class="ta-page-title">
                                <h1>{{ copy.works }}</h1>
                                <p>{{ app.tenant.description }}</p>
                            </div>
                            <div class="ta-filter-row ta-media-filter-row">
                                <div
                                    class="ta-work-filter-menu ta-media-filter-menu"
                                >
                                    <button
                                        :class="{
                                            active:
                                                mediaFilter === 'mixed' ||
                                                workFilter !== 'all',
                                        }"
                                        :aria-expanded="workFilterOpen"
                                        @click="
                                            workFilterOpen = !workFilterOpen
                                        "
                                    >
                                        <AppIcon name="menu" :size="18" />
                                        {{ portfolioLabels.filters }}
                                    </button>
                                    <div
                                        v-if="workFilterOpen"
                                        class="ta-work-filter-popover"
                                    >
                                        <button
                                            :class="{
                                                active:
                                                    workFilter === 'all' &&
                                                    mediaFilter === 'mixed',
                                            }"
                                            @click="selectMediaFilter('mixed')"
                                        >
                                            {{ portfolioLabels.all }}
                                        </button>
                                        <button
                                            :class="{
                                                active:
                                                    workFilter === 'favorites',
                                            }"
                                            @click="
                                                selectWorkFilter('favorites')
                                            "
                                        >
                                            <AppIcon name="heart" :size="18" />
                                            {{ portfolioLabels.favorites }}
                                        </button>
                                        <button
                                            v-if="hasBeforeAfterPortfolio"
                                            :class="{
                                                active:
                                                    workFilter ===
                                                    'before_after',
                                            }"
                                            @click="
                                                selectWorkFilter('before_after')
                                            "
                                        >
                                            {{ copy.featured }}
                                        </button>
                                        <small>{{
                                            portfolioLabels.perPage
                                        }}</small>
                                        <div class="ta-page-size-options">
                                            <button
                                                v-for="size in [6, 12, 24, 48]"
                                                :key="size"
                                                :class="{
                                                    active:
                                                        portfolioPageSize ===
                                                        size,
                                                }"
                                                @click="
                                                    selectPortfolioPageSize(
                                                        size,
                                                    )
                                                "
                                            >
                                                {{ size }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <button
                                    :class="{
                                        active: mediaFilter === 'photos',
                                    }"
                                    @click="selectMediaFilter('photos')"
                                >
                                    {{ mediaLabels.photos }}
                                </button>
                                <button
                                    :class="{
                                        active: mediaFilter === 'videos',
                                    }"
                                    @click="selectMediaFilter('videos')"
                                >
                                    {{ mediaLabels.videos }}
                                </button>
                            </div>
                            <div
                                v-if="paginatedPortfolio.length"
                                class="ta-portfolio-list"
                            >
                                <article
                                    v-for="item in paginatedPortfolio"
                                    :key="item.id"
                                >
                                    <video
                                        v-if="item.video"
                                        class="ta-portfolio-video"
                                        :src="item.video"
                                        controls
                                        playsinline
                                        preload="metadata"
                                    ></video
                                    ><BeforeAfterSlider
                                        v-else-if="
                                            item.before_image &&
                                            item.after_image
                                        "
                                        :before="item.before_image"
                                        :after="item.after_image"
                                        :before-label="copy.before"
                                        :after-label="copy.after"
                                        :alt="item.title"
                                        @open="
                                            openLightbox($event.src, $event.alt)
                                        "
                                    /><button
                                        v-else
                                        class="ta-portfolio-image-button"
                                        @click="
                                            openLightbox(
                                                item.image ||
                                                    item.after_image ||
                                                    item.before_image,
                                                item.title,
                                            )
                                        "
                                    >
                                        <img
                                            :src="
                                                item.image ||
                                                item.after_image ||
                                                item.before_image
                                            "
                                            :alt="item.title"
                                        />
                                    </button>
                                    <div>
                                        <header>
                                            <h2>{{ item.title }}</h2>
                                            <button
                                                :class="{
                                                    favorite: isFavorite(
                                                        item.id,
                                                    ),
                                                }"
                                                :aria-pressed="
                                                    isFavorite(item.id)
                                                "
                                                @click="toggleFavorite(item.id)"
                                            >
                                                <AppIcon name="heart" />
                                            </button>
                                        </header>
                                        <p>{{ item.description }}</p>
                                    </div>
                                </article>
                            </div>
                            <nav
                                v-if="portfolioPageCount > 1"
                                class="ta-pagination"
                                aria-label="Portfolio pages"
                            >
                                <button
                                    :disabled="portfolioPage === 1"
                                    @click="portfolioPage--"
                                >
                                    ←
                                </button>
                                <span
                                    >{{ portfolioPage }} /
                                    {{ portfolioPageCount }}</span
                                >
                                <button
                                    :disabled="
                                        portfolioPage === portfolioPageCount
                                    "
                                    @click="portfolioPage++"
                                >
                                    →
                                </button>
                            </nav>
                            <div
                                v-if="!paginatedPortfolio.length"
                                class="ta-empty"
                            >
                                <AppIcon name="works" :size="46" />
                                <h2>{{ copy.noActivity }}</h2>
                            </div>
                        </section>

                        <TenantActivity
                            v-else-if="screen === 'activity'"
                            :app="app"
                            :copy="copy"
                            :activity="activity"
                            :loading="activityLoading"
                            :action-screen="actionScreen"
                            :locale="locale"
                            :selected="selectedRequest"
                            v-model:message="message"
                            :sending="sending"
                            :status-label="statusLabel"
                            @navigate="go"
                            @select="selectedRequest = $event"
                            @reschedule="rescheduleAppointment = $event"
                            @cancel="cancelAppointment"
                            @send="sendMessage"
                        />

                        <TenantReviews
                            v-else-if="screen === 'reviews'"
                            :app="app"
                            :copy="copy"
                            :locale="locale"
                            :average-rating="averageRating"
                            :notice="reviewNotice"
                            v-model:open="reviewOpen"
                            :busy="reviewBusy"
                            :form="reviewForm"
                            @close="go('home')"
                            @submit="submitReview"
                        />
                        <TenantContactsSheet
                            v-else-if="screen === 'contacts'"
                            :app="app"
                            :copy="copy"
                            :address="address"
                            :contact-name="contactName"
                            @close="go('home')"
                        />
                        <section v-else class="ta-page ta-empty">
                            <h1>404</h1>
                            <button class="ta-gold-button" @click="go('home')">
                                {{ copy.home }}
                            </button>
                        </section>

                        <div
                            v-if="lightbox"
                            class="ta-image-lightbox"
                            role="dialog"
                            aria-modal="true"
                            :aria-label="lightbox.alt"
                            @click.self="lightbox = null"
                        >
                            <button
                                :aria-label="portfolioLabels.close"
                                @click="lightbox = null"
                            >
                                <AppIcon name="close" />
                            </button>
                            <figure>
                                <img :src="lightbox.src" :alt="lightbox.alt" />
                                <figcaption>
                                    <strong>{{ lightbox.alt }}</strong>
                                    <span>{{
                                        portfolioLabels.lightboxHint
                                    }}</span>
                                </figcaption>
                            </figure>
                        </div>
                        <div
                            v-if="videoLightbox"
                            class="ta-image-lightbox ta-video-lightbox"
                            role="dialog"
                            aria-modal="true"
                            :aria-label="videoLightbox.title"
                            @click.self="videoLightbox = null"
                        >
                            <button
                                :aria-label="videoLabels.close"
                                @click="videoLightbox = null"
                            >
                                ×
                            </button>
                            <figure>
                                <video
                                    :src="videoLightbox.src"
                                    controls
                                    autoplay
                                    playsinline
                                    preload="metadata"
                                ></video>
                                <figcaption>
                                    <strong>{{ videoLightbox.title }}</strong>
                                </figcaption>
                            </figure>
                        </div>
                    </div>

                    <TenantBottomNav
                        :screen="screen"
                        :items="navItems"
                        :label="copy.navigation"
                        @navigate="go"
                    />
                    <TenantMenuOverlay
                        v-if="menuOpen"
                        :app="app"
                        :copy="copy"
                        :locale="locale"
                        :is-brows="isBrows"
                        :push-state-label="pushStateLabel"
                        @close="menuOpen = false"
                        @navigate="go"
                        @share="share"
                        @install="showInstall"
                        @notifications="openPushSettings"
                        @change-locale="changeLocale"
                    />
                    <TenantInstallPrompt
                        v-if="installOpen"
                        :copy="copy"
                        :platform="installPlatform"
                        :can-install="Boolean(installPrompt)"
                        :installed="appInstalled"
                        @close="installOpen = false"
                        @install="installApp"
                    />
                    <TenantLanguagePrompt
                        v-if="languageOpen && hasMultipleLocales"
                        :locales="app.template.locales"
                        :current="locale"
                        @select="changeLocale"
                        @close="languageOpen = false"
                    />
                    <TenantPushPrompt
                        v-if="pushPrompt"
                        :copy="copy"
                        :status="pushStatus"
                        :busy="pushBusy"
                        :state="pushState"
                        @enable="enablePush"
                        @dismiss="dismissPush"
                    />
                    <TenantPushNudge
                        v-if="pushNudge && !pushPrompt && !menuOpen"
                        :copy="copy"
                        :state="pushState"
                        @open="openPushSettings"
                        @dismiss="dismissPushNudge"
                    />
                </template>
            </main>
            <TenantDesktopAside :app="app" :copy="copy" @share="share" />
        </div>
    </div>
</template>

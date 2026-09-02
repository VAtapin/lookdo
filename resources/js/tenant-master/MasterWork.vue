<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import QRCode from "qrcode";
import { api } from "../api";
import BeforeAfterSlider from "../tenant-app/BeforeAfterSlider.vue";

const props = defineProps<{
    tenantId: number;
    locale: string;
    t: (key: string) => string;
}>();
const data = ref<any>({
    portfolio: [],
    reviews: [],
    social: [],
    social_connections: [],
    social_providers: {},
    entitlements: {},
    share_url: "",
});
const tab = ref(
        new URLSearchParams(window.location.search).get("tab") || "portfolio",
    ),
    busy = ref(false),
    error = ref(""),
    notice = ref(""),
    socialConnectionError = ref(""),
    aiContext = ref(""),
    aiResult = ref(""),
    qrCode = ref("");
const work = reactive<any>({
    id: null,
    title: "",
    description: "",
    featured: false,
    published: false,
    publication_confirmed: false,
    image: null,
    video: null,
    video_url: "",
    remove_video: false,
    before: null,
    after: null,
});
const review = reactive<any>({
    id: null,
    rating: 5,
    author_name: "",
    body: "",
    master_reply: "",
    published: false,
    publication_confirmed: false,
});
const social = reactive<any>({
    id: null,
    portfolio_item_id: "",
    format: "feed",
    channel: "share",
    locale: props.locale,
    caption: "",
    image_path: "",
    image: null,
    image_url: "",
    booking_url: "",
    status: "draft",
});
const channels = [
    "share",
    "instagram",
    "facebook",
    "whatsapp",
    "telegram",
    "viber",
    "vk",
    "linkedin",
    "x",
];
const directChannels = ["instagram", "facebook", "telegram", "vk"];
const telegramTarget = ref("");
const providerModal = ref("");
const providerForm = reactive({ client_id: "", client_secret: "", bot_token: "" });
const providerHelpUrl = computed(() =>
    providerModal.value === "telegram"
        ? "https://core.telegram.org/bots/tutorial#obtain-your-bot-token"
        : providerModal.value === "vk"
          ? "https://id.vk.com/about/business/go/docs/ru/vkid/latest/vk-id/connection/create-application"
          : "https://developers.facebook.com/apps/",
);
const providerCallbackUrl = computed(() =>
    providerModal.value && providerModal.value !== "telegram"
        ? `${window.location.origin}/api/social/oauth/${providerModal.value}/callback`
        : "",
);
const enabled = (key: string) =>
    String(data.value.entitlements?.[key] || "0") === "1";
const hasBeforeAfter = computed(() => enabled("before_after_enabled")),
    hasVideo = computed(() => enabled("video_enabled")),
    hasSocial = computed(() => enabled("social_content_enabled")),
    hasAi = computed(() => enabled("ai_communication_enabled"));
const tabs = computed(() => [
    "portfolio",
    "reviews",
    ...(hasSocial.value ? ["social"] : []),
    ...(hasAi.value ? ["aiAssist"] : []),
]);
const localized = (x: any) =>
    x?.[props.locale] || x?.de || Object.values(x || {})[0] || "";
const socialWork = computed(() =>
    data.value.portfolio.find(
        (item: any) => String(item.id) === String(social.portfolio_item_id),
    ),
);
const socialPreview = computed(
    () =>
        social.image_url ||
        socialWork.value?.after_image_url ||
        socialWork.value?.image_url ||
        socialWork.value?.before_image_url ||
        "",
);
const socialConnection = computed(() =>
    data.value.social_connections?.find(
        (item: any) => item.provider === social.channel && item.status === "active",
    ),
);
const providerConfigured = computed(
    () => !!data.value.social_providers?.[social.channel]?.configured,
);
const isDirectChannel = computed(() => directChannels.includes(social.channel));

async function load() {
    busy.value = true;
    error.value = "";
    try {
        data.value = await api(`/tenant/${props.tenantId}/content-workspace`);
        if (!tabs.value.includes(tab.value)) tab.value = "portfolio";
        if (!social.booking_url)
            social.booking_url = data.value.share_url || "";
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
function resetWork() {
    Object.assign(work, {
        id: null,
        title: "",
        description: "",
        featured: false,
        published: false,
        publication_confirmed: false,
        image: null,
        video: null,
        video_url: "",
        remove_video: false,
        before: null,
        after: null,
    });
}
function editWork(item: any) {
    Object.assign(work, {
        id: item.id,
        title: localized(item.title),
        description: localized(item.description),
        featured: !!item.featured,
        published: !!item.published,
        publication_confirmed: !!item.published,
        image: null,
        video: null,
        video_url: item.video_url || "",
        remove_video: false,
        before: null,
        after: null,
    });
    window.scrollTo({ top: 0, behavior: "smooth" });
}
async function saveWork() {
    const f = new FormData();
    if (work.id) f.append("_method", "PUT");
    f.append(`title[${props.locale}]`, work.title);
    f.append(`description[${props.locale}]`, work.description);
    f.append("source_locale", props.locale);
    for (const x of ["featured", "published", "publication_confirmed"])
        f.append(x, work[x] ? "1" : "0");
    f.append("remove_video", work.remove_video ? "1" : "0");
    for (const x of ["image", "video", "before", "after"])
        if (work[x]) f.append(x, work[x]);
    busy.value = true;
    error.value = "";
    try {
        await api(
            `/tenant/${props.tenantId}/portfolio${work.id ? "/" + work.id : ""}`,
            { method: "POST", body: f },
        );
        resetWork();
        await load();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
function resetReview() {
    Object.assign(review, {
        id: null,
        rating: 5,
        author_name: "",
        body: "",
        master_reply: "",
        published: false,
        publication_confirmed: false,
    });
}
function editReview(item: any) {
    Object.assign(review, {
        id: item.id,
        rating: item.rating,
        author_name: item.author_name || "",
        body: item.body || "",
        master_reply: item.master_reply || "",
        published: !!item.published,
        publication_confirmed: !!item.published,
    });
}
async function saveReview() {
    busy.value = true;
    error.value = "";
    try {
        await api(
            `/tenant/${props.tenantId}/reviews${review.id ? "/" + review.id : ""}`,
            {
                method: review.id ? "PUT" : "POST",
                body: JSON.stringify(review),
            },
        );
        resetReview();
        await load();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
function selectSocialWork() {
    const item = socialWork.value;
    if (!item) return;
    social.image_path =
        item.after_image_path ||
        item.image_path ||
        item.before_image_path ||
        "";
    social.image_url =
        item.after_image_url || item.image_url || item.before_image_url || "";
    if (!social.caption)
        social.caption = [localized(item.title), localized(item.description)]
            .filter(Boolean)
            .join(". ");
}
function resetSocial() {
    Object.assign(social, {
        id: null,
        portfolio_item_id: "",
        format: "feed",
        channel: "share",
        locale: props.locale,
        caption: "",
        image_path: "",
        image: null,
        image_url: "",
        booking_url: data.value.share_url || "",
        status: "draft",
    });
}
function editSocial(item: any) {
    Object.assign(social, {
        id: item.id,
        portfolio_item_id: item.portfolio_item_id || "",
        format: item.format,
        channel: item.channel,
        locale: item.locale,
        caption: item.caption || "",
        image_path: item.image_path || "",
        image: null,
        image_url: item.image_url || "",
        booking_url: item.booking_url || data.value.share_url || "",
        status: item.status,
    });
    tab.value = "social";
}
function setSocialImage(file?: File) {
    social.image = file || null;
    if (file) {
        social.image_url = URL.createObjectURL(file);
        social.image_path = "";
    }
}
async function persistSocial(status = "draft") {
    const f = new FormData();
    if (social.id) f.append("_method", "PUT");
    for (const key of ["format", "channel", "locale", "caption", "booking_url"])
        f.append(key, String(social[key] || ""));
    f.append("status", status);
    if (social.portfolio_item_id)
        f.append("portfolio_item_id", String(social.portfolio_item_id));
    if (social.image_path) f.append("image_path", social.image_path);
    if (social.image) f.append("image", social.image);
    const r: any = await api(
        `/tenant/${props.tenantId}/social-drafts${social.id ? "/" + social.id : ""}`,
        { method: "POST", body: f },
    );
    social.id = r.draft.id;
    social.status = r.draft.status;
    social.image_path = r.draft.image_path || social.image_path;
    social.image_url = r.draft.image_url || social.image_url;
    return r.draft;
}
async function saveSocial() {
    busy.value = true;
    error.value = "";
    notice.value = "";
    try {
        await persistSocial("draft");
        notice.value = props.t("socialSaved");
        await load();
        resetSocial();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function previewFile() {
    let source: Blob | null = social.image;
    if (!source && !socialPreview.value) return null;
    try {
        if (!source) {
            const response = await fetch(socialPreview.value);
            if (!response.ok) return null;
            source = await response.blob();
        }

        const objectUrl = URL.createObjectURL(source);
        const image = new Image();
        image.src = objectUrl;
        await image.decode();
        const canvas = document.createElement("canvas");
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        const context = canvas.getContext("2d");
        if (!context) return null;
        context.fillStyle = "#ffffff";
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0);
        const jpeg = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, "image/jpeg", 0.92),
        );
        URL.revokeObjectURL(objectUrl);
        if (!jpeg) return null;
        const tenantName = String(data.value.tenant?.name || "lookdo")
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, "-")
            .replace(/^-|-$/g, "") || "lookdo";
        return new File([jpeg], `${tenantName}.jpg`, { type: "image/jpeg" });
    } catch {
        return null;
    }
}
function shareText(caption: string, url: string) {
    const canonicalUrl = String(url || "").replace(/\/+$/, "");
    if (!canonicalUrl) return caption.trim();
    const escapedUrl = canonicalUrl.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const cleanCaption = caption
        .replace(new RegExp(`${escapedUrl}/?`, "gi"), "")
        .replace(/\n{3,}/g, "\n\n")
        .trim();
    return [cleanCaption, canonicalUrl].filter(Boolean).join("\n\n");
}
async function shareSocial() {
    if (!social.caption.trim()) return;
    busy.value = true;
    error.value = "";
    notice.value = "";
    try {
        const draft = await persistSocial("ready");
        if (isDirectChannel.value) {
            if (!providerConfigured.value)
                throw new Error(props.t("socialProviderUnavailable"));
            if (!socialConnection.value)
                throw new Error(props.t("socialConnectRequired"));
            const published: any = await api(
                `/tenant/${props.tenantId}/social-drafts/${draft.id}/publish`,
                { method: "POST" },
            );
            notice.value = props.t("socialPublished");
            if (published.publication?.url)
                window.open(published.publication.url, "_blank", "noopener,noreferrer");
            await load();
            return;
        }
        const url = social.booking_url || data.value.share_url;
        const text = shareText(social.caption, url);
        if (social.channel === "share" && navigator.share) {
            const payload: any = {
                title:
                    localized(socialWork.value?.title) || data.value.share_url,
                text,
            };
            const file = await previewFile();
            if (file && navigator.canShare?.({ files: [file] }))
                payload.files = [file];
            await navigator.share(payload);
        } else {
            await navigator.clipboard?.writeText(text);
            const encodedText = encodeURIComponent(text),
                encodedUrl = encodeURIComponent(url),
                encodedTitle = encodeURIComponent(social.caption);
            const links: any = {
                whatsapp: `https://wa.me/?text=${encodedText}`,
                viber: `viber://forward?text=${encodedText}`,
                linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${encodedUrl}`,
                x: `https://x.com/intent/post?url=${encodedUrl}&text=${encodedTitle}`,
            };
            window.open(
                links[social.channel] || url,
                "_blank",
                "noopener,noreferrer",
            );
        }
        notice.value = props.t("socialReady");
    } catch (e: any) {
        if (e?.name !== "AbortError") error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function connectSocial(provider: string) {
    socialConnectionError.value = "";
    if (!data.value.social_providers?.[provider]?.configured) {
        openProviderConfig(provider);
        return;
    }
    busy.value = true;
    error.value = "";
    try {
        const result: any = await api(
            `/tenant/${props.tenantId}/social-connections/${provider}/authorize`,
            {
                method: "POST",
                body: JSON.stringify(
                    provider === "telegram"
                        ? { target: telegramTarget.value.trim() }
                        : {},
                ),
            },
        );
        if (result.connected) {
            telegramTarget.value = "";
            await load();
            return;
        }
        window.location.assign(result.authorization_url);
    } catch (e: any) {
        error.value = e.message;
        socialConnectionError.value = e.message;
        busy.value = false;
    }
}
function openProviderConfig(provider: string) {
    providerModal.value = provider;
    Object.assign(providerForm, { client_id: "", client_secret: "", bot_token: "" });
    socialConnectionError.value = "";
}
async function saveProviderConfig() {
    busy.value = true;
    socialConnectionError.value = "";
    try {
        const body = providerModal.value === "telegram"
            ? { bot_token: providerForm.bot_token }
            : { client_id: providerForm.client_id, client_secret: providerForm.client_secret };
        await api(`/tenant/${props.tenantId}/social-providers/${providerModal.value}`, {
            method: "PUT",
            body: JSON.stringify(body),
        });
        const configuredProvider = providerModal.value;
        providerModal.value = "";
        await load();
        notice.value = props.t("socialProviderSaved");
        if (configuredProvider !== "telegram") await connectSocial(configuredProvider);
    } catch (e: any) {
        socialConnectionError.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function disconnectSocial(provider: string) {
    if (!confirm(props.t("socialDisconnectConfirm"))) return;
    busy.value = true;
    error.value = "";
    try {
        await api(`/tenant/${props.tenantId}/social-connections/${provider}`, {
            method: "DELETE",
        });
        await load();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function remove(kind: string, id: number) {
    if (!confirm(props.t("confirmDelete"))) return;
    const url =
        kind === "portfolio"
            ? `/tenant/${props.tenantId}/portfolio/${id}`
            : kind === "reviews"
              ? `/tenant/${props.tenantId}/reviews/${id}`
              : `/tenant/${props.tenantId}/social-drafts/${id}`;
    await api(url, { method: "DELETE" });
    await load();
}
async function ai(task: string) {
    const item = socialWork.value;
    const context =
        task === "social"
            ? [
                  localized(item?.title),
                  localized(item?.description),
                  social.caption,
                  social.booking_url,
              ]
                  .filter(Boolean)
                  .join("\n")
            : aiContext.value;
    if (!context.trim()) return;
    busy.value = true;
    error.value = "";
    try {
        const r: any = await api(`/tenant/${props.tenantId}/workspace/ai`, {
            method: "POST",
            body: JSON.stringify({ task, locale: props.locale, context }),
        });
        aiResult.value = r.text;
        if (task === "social") social.caption = r.text;
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function makeQr() {
    qrCode.value = social.booking_url
        ? await QRCode.toDataURL(social.booking_url, {
              width: 320,
              margin: 2,
              color: { dark: "#111318", light: "#ffffff" },
          })
        : "";
}
watch(() => social.portfolio_item_id, selectSocialWork);
watch(() => social.booking_url, makeQr);
onMounted(async () => {
    await load();
    await makeQr();
});
</script>

<template>
    <section class="mw-stack">
        <header class="mw-page-head">
            <div>
                <p class="mw-kicker">LOOKDO</p>
                <h1>{{ t("works") }}</h1>
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
        <p v-if="notice" class="mw-success">{{ notice }}</p>

        <div v-if="tab === 'portfolio'" class="mw-two">
            <div class="mw-work-grid">
                <article v-for="item in data.portfolio" :key="item.id">
                    <BeforeAfterSlider
                        v-if="item.before_image_url && item.after_image_url"
                        :before="item.before_image_url"
                        :after="item.after_image_url"
                        :before-label="t('before')"
                        :after-label="t('after')"
                        :alt="localized(item.title)"
                    /><video
                        v-else-if="item.video_url"
                        :src="item.video_url"
                        controls
                        playsinline
                        preload="metadata"
                    ></video
                    ><img
                        v-else-if="
                            item.image_url ||
                            item.after_image_url ||
                            item.before_image_url
                        "
                        :src="
                            item.image_url ||
                            item.after_image_url ||
                            item.before_image_url
                        "
                    /><span
                        ><b>{{ localized(item.title) || t("works") }}</b
                        ><small>{{
                            item.published ? t("published") : t("scheduled")
                        }}</small></span
                    >
                    <div class="mw-card-actions">
                        <button class="mw-secondary" @click="editWork(item)">
                            {{ t("edit") }}</button
                        ><button
                            class="mw-danger-link"
                            @click="remove('portfolio', item.id)"
                        >
                            {{ t("delete") }}
                        </button>
                    </div>
                </article>
                <p v-if="!data.portfolio.length" class="mw-empty">
                    {{ t("noItems") }}
                </p>
            </div>
            <form class="mw-panel mw-form" @submit.prevent="saveWork">
                <h2>{{ t(work.id ? "editWork" : "addWork") }}</h2>
                <label
                    >{{ t("title")
                    }}<input v-model="work.title" required /></label
                ><label
                    >{{ t("description")
                    }}<textarea v-model="work.description"></textarea></label
                ><label
                    >{{ t("image")
                    }}<input
                        type="file"
                        accept="image/*"
                        @change="
                            work.image = (
                                $event.target as HTMLInputElement
                            ).files?.[0]
                        " /></label
                ><label v-if="hasVideo"
                    >{{ t("video")
                    }}<input
                        type="file"
                        accept="video/mp4,video/webm,video/quicktime"
                        @change="
                            work.video = (
                                $event.target as HTMLInputElement
                            ).files?.[0]
                        " /><small>{{ t("portfolioVideoHint") }}</small></label
                ><label v-if="work.video_url" class="mw-check"
                    ><input v-model="work.remove_video" type="checkbox" />{{
                        t("removeVideo")
                    }}</label
                ><template v-if="hasBeforeAfter"
                    ><p class="mw-form-hint">{{ t("beforeAfterHint") }}</p>
                    <label
                        >{{ t("before")
                        }}<input
                            type="file"
                            accept="image/*"
                            @change="
                                work.before = (
                                    $event.target as HTMLInputElement
                                ).files?.[0]
                            " /></label
                    ><label
                        >{{ t("after")
                        }}<input
                            type="file"
                            accept="image/*"
                            @change="
                                work.after = (
                                    $event.target as HTMLInputElement
                                ).files?.[0]
                            " /></label></template
                ><label class="mw-check"
                    ><input v-model="work.featured" type="checkbox" />{{
                        t("featured")
                    }}</label
                ><label class="mw-check"
                    ><input v-model="work.published" type="checkbox" />{{
                        t("published")
                    }}</label
                ><label v-if="work.published" class="mw-check mw-warning"
                    ><input
                        v-model="work.publication_confirmed"
                        type="checkbox"
                    />{{ t("consentRequired") }}</label
                ><button class="mw-primary" :disabled="busy">
                    {{ t("save") }}</button
                ><button
                    v-if="work.id"
                    type="button"
                    class="mw-secondary"
                    @click="resetWork"
                >
                    {{ t("close") }}
                </button>
            </form>
        </div>

        <div v-if="tab === 'reviews'" class="mw-two">
            <div class="mw-panel">
                <article
                    v-for="item in data.reviews"
                    :key="item.id"
                    class="mw-review"
                >
                    <strong
                        >{{ "★".repeat(item.rating)
                        }}{{ "☆".repeat(5 - item.rating) }}</strong
                    ><b>{{ item.author_name }}</b>
                    <p>{{ item.body }}</p>
                    <blockquote
                        v-if="item.master_reply"
                        class="mw-review-reply"
                    >
                        <strong>{{ t("masterReply") }}</strong>
                        <span>{{ item.master_reply }}</span>
                    </blockquote>
                    <small>{{
                        item.published ? t("published") : t("scheduled")
                    }}</small>
                    <div class="mw-card-actions">
                        <button class="mw-secondary" @click="editReview(item)">
                            {{ t("edit") }}</button
                        ><button
                            class="mw-danger-link"
                            @click="remove('reviews', item.id)"
                        >
                            {{ t("delete") }}
                        </button>
                    </div>
                </article>
                <p v-if="!data.reviews.length" class="mw-empty">
                    {{ t("noItems") }}
                </p>
            </div>
            <form class="mw-panel mw-form" @submit.prevent="saveReview">
                <h2>{{ t("reviews") }}</h2>
                <label
                    >{{ t("rating")
                    }}<input
                        v-model.number="review.rating"
                        type="number"
                        min="1"
                        max="5" /></label
                ><label
                    >{{ t("customer")
                    }}<input v-model="review.author_name" /></label
                ><label
                    >{{ t("description")
                    }}<textarea v-model="review.body"></textarea></label
                ><label
                    >{{ t("masterReply")
                    }}<textarea v-model="review.master_reply"></textarea></label
                ><label class="mw-check"
                    ><input v-model="review.published" type="checkbox" />{{
                        t("published")
                    }}</label
                ><label v-if="review.published" class="mw-check mw-warning"
                    ><input
                        v-model="review.publication_confirmed"
                        type="checkbox"
                    />{{ t("consentRequired") }}</label
                ><button class="mw-primary" :disabled="busy">
                    {{ t("save") }}</button
                ><button
                    v-if="review.id"
                    type="button"
                    class="mw-secondary"
                    @click="resetReview"
                >
                    {{ t("close") }}
                </button>
            </form>
        </div>

        <div v-if="tab === 'social' && hasSocial" class="mw-social-layout">
            <article class="mw-social-preview">
                <div
                    class="mw-social-canvas"
                    :class="`format-${social.format}`"
                >
                    <img v-if="socialPreview" :src="socialPreview" />
                    <div class="mw-social-shade"></div>
                    <div>
                        <img v-if="qrCode" class="mw-social-qr" :src="qrCode" />
                        <p>{{ social.caption || t("socialHint") }}</p>
                        <small>{{ social.booking_url }}</small>
                    </div>
                </div>
                <a
                    v-if="qrCode"
                    class="mw-secondary"
                    :href="qrCode"
                    download="lookdo-qr.png"
                    >{{ t("downloadQr") }}</a
                >
            </article>
            <form class="mw-panel mw-form" @submit.prevent="saveSocial">
                <h2>{{ t("socialComposer") }}</h2>
                <section class="mw-social-connections full">
                    <b>{{ t("socialAccounts") }}</b>
                    <p v-if="socialConnectionError" class="mw-warning">{{ socialConnectionError }}</p>
                    <div v-for="provider in directChannels" :key="provider">
                        <span>
                            <strong>{{ t(provider) }}</strong>
                            <small v-if="data.social_connections?.find((item:any) => item.provider === provider && item.status === 'active')">
                                {{ data.social_connections.find((item:any) => item.provider === provider && item.status === 'active')?.account_name || t("socialConnected") }}
                            </small>
                            <small v-else-if="!data.social_providers?.[provider]?.configured">{{ t("socialProviderNeedsSetup") }}</small>
                            <small v-else>{{ t("socialNotConnected") }}</small>
                        </span>
                        <input
                            v-if="provider === 'telegram' && !data.social_connections?.find((item:any) => item.provider === provider && item.status === 'active') && data.social_providers?.[provider]?.configured"
                            v-model="telegramTarget"
                            type="text"
                            :placeholder="t('telegramTargetPlaceholder')"
                            :aria-label="t('telegramTarget')"
                        />
                        <button
                            v-if="data.social_connections?.find((item:any) => item.provider === provider && item.status === 'active')"
                            type="button"
                            class="mw-secondary"
                            :disabled="busy"
                            @click="disconnectSocial(provider)"
                        >{{ t("disconnect") }}</button>
                        <button
                            v-else
                            type="button"
                            class="mw-secondary"
                            :disabled="busy || (provider === 'telegram' && data.social_providers?.[provider]?.configured && !telegramTarget.trim())"
                            @click="connectSocial(provider)"
                        >{{ data.social_providers?.[provider]?.configured ? t("connect") : t("configure") }}</button>
                    </div>
                </section>
                <label
                    >{{ t("portfolio")
                    }}<select v-model="social.portfolio_item_id">
                        <option value="">—</option>
                        <option
                            v-for="item in data.portfolio"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ localized(item.title) }}
                        </option>
                    </select></label
                ><label
                    >{{ t("format")
                    }}<select v-model="social.format">
                        <option value="feed">{{ t("feed") }}</option>
                        <option value="story">{{ t("story") }}</option>
                        <option value="status">{{ t("status") }}</option>
                    </select></label
                ><label
                    >{{ t("channel")
                    }}<select v-model="social.channel">
                        <option v-for="x in channels" :key="x" :value="x">
                            {{ t(x) }}
                        </option>
                    </select></label
                ><label
                    >{{ t("socialImage")
                    }}<input
                        type="file"
                        accept="image/*"
                        @change="
                            setSocialImage(
                                ($event.target as HTMLInputElement).files?.[0],
                            )
                        " /></label
                ><label class="full"
                    >{{ t("caption")
                    }}<textarea
                        v-model="social.caption"
                        rows="6"
                    ></textarea></label
                ><button
                    v-if="hasAi"
                    type="button"
                    class="mw-secondary"
                    :disabled="busy || !social.portfolio_item_id"
                    @click="ai('social')"
                >
                    {{ t("aiSocial") }}</button
                ><label class="full"
                    >{{ t("bookingLink")
                    }}<input v-model="social.booking_url" type="url" /></label
                ><button class="mw-secondary" :disabled="busy">
                    {{ t("saveDraft") }}</button
                ><button
                    type="button"
                    class="mw-primary"
                    :disabled="busy || !social.caption.trim()"
                    @click="shareSocial"
                >
                    {{ isDirectChannel ? t("publishDirect") : t("publishNow") }}
                </button>
            </form>
            <article class="mw-panel mw-social-drafts">
                <h2>{{ t("drafts") }}</h2>
                <button
                    v-for="item in data.social"
                    :key="item.id"
                    @click="editSocial(item)"
                >
                    <img v-if="item.image_url" :src="item.image_url" /><span
                        ><b>{{ t(item.channel) }} · {{ t(item.format) }}</b
                        ><small>{{ item.caption }}</small></span
                    ><em>{{ t(item.status) }}</em
                    ><i @click.stop="remove('social', item.id)">×</i>
                </button>
                <p v-if="!data.social.length" class="mw-empty">
                    {{ t("noItems") }}
                </p>
            </article>
        </div>

        <div v-if="tab === 'aiAssist' && hasAi" class="mw-panel mw-ai">
            <h2>{{ t("aiAssist") }}</h2>
            <textarea
                v-model="aiContext"
                rows="8"
                :placeholder="t('description')"
            ></textarea>
            <div>
                <button class="mw-secondary" @click="ai('reply')">
                    {{ t("aiReply") }}</button
                ><button class="mw-secondary" @click="ai('reminder')">
                    {{ t("aiReminder") }}</button
                ><button
                    v-if="hasSocial"
                    class="mw-secondary"
                    @click="ai('social')"
                >
                    {{ t("aiSocial") }}
                </button>
            </div>
            <textarea v-if="aiResult" v-model="aiResult" rows="8"></textarea>
        </div>
    </section>
    <div v-if="providerModal" class="mw-brand-modal" @click.self="providerModal = ''">
        <form class="mw-panel mw-form" @submit.prevent="saveProviderConfig">
            <header>
                <div>
                    <small>{{ t("socialProviderSetup") }}</small>
                    <h2>{{ t(providerModal) }}</h2>
                </div>
                <button type="button" :disabled="busy" @click="providerModal = ''">×</button>
            </header>
            <p class="mw-warning" v-if="socialConnectionError">{{ socialConnectionError }}</p>
            <div class="mw-provider-help">
                <b>{{ t("socialProviderHelpTitle") }}</b>
                <ol>
                    <li>{{ t(`socialProviderHelp_${providerModal}_1`) }}</li>
                    <li>{{ t(`socialProviderHelp_${providerModal}_2`) }}</li>
                    <li>{{ t(`socialProviderHelp_${providerModal}_3`) }}</li>
                </ol>
                <a :href="providerHelpUrl" target="_blank" rel="noopener noreferrer">{{ t("openOfficialInstructions") }} ↗</a>
            </div>
            <template v-if="providerModal === 'telegram'">
                <label>{{ t("telegramBotToken") }}<input v-model="providerForm.bot_token" type="password" required autocomplete="new-password" /></label>
            </template>
            <template v-else>
                <label>{{ t("applicationId") }}<input v-model="providerForm.client_id" required autocomplete="off" /></label>
                <label>{{ t("applicationSecret") }}<input v-model="providerForm.client_secret" type="password" required autocomplete="new-password" /></label>
                <label>{{ t("callbackUrl") }}<input :value="providerCallbackUrl" readonly @focus="($event.target as HTMLInputElement).select()" /></label>
                <p>{{ t("callbackUrlHint") }}</p>
            </template>
            <p>{{ t("secretStorageHint") }}</p>
            <div>
                <button type="button" class="mw-secondary" :disabled="busy" @click="providerModal = ''">{{ t("cancel") }}</button>
                <button class="mw-primary" :disabled="busy">{{ t("saveAndConnect") }}</button>
            </div>
        </form>
    </div>
</template>

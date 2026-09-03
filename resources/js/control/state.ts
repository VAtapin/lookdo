import { reactive, ref } from "vue";

export function createControlState() {
    const filters = reactive({
        search: "",
        status: "",
        secondary: "",
        actor_id: "",
        tenant_id: "",
        action: "",
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

    return {
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
    };
}

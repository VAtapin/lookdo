import { api } from "../api";

export function usePageTranslations(deps: any) {
    const {
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
    } = deps;

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
        if (!pageTranslation.pageId || pageTranslation.phase !== "ready")
            return;
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

    return {
        editPage,
        closePageEditor,
        openPageTranslationResult,
        savePage,
        translatePage,
    };
}

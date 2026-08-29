import type { MasterLocale } from "./copy";

const brandingExtra: Record<MasterLocale, Record<string, string>> = {
    de: {
        branding: "Auftritt & Bilder",
        brandingIntro:
            "Logo, Startbild und öffentliche Darstellung Ihrer App verwalten.",
        brandingOnboarding:
            "Beantworten Sie kurz die Fragen und wählen Sie anschließend eigene oder mit KI vorbereitete Bilder.",
        questionnaire: "Über Ihren Betrieb",
        businessDescription: "Was macht Ihr Betrieb?",
        customers: "Für welche Kunden arbeiten Sie?",
        visualStyle: "Gewünschte Wirkung und Stil",
        avoid: "Was darf nicht im Bild erscheinen?",
        tagline: "Kurze Überschrift",
        logo: "Logo / App-Symbol",
        heroImage: "Startbild der App",
        upload: "Eigenes Bild laden",
        generateAi: "Mit KI erstellen",
        uploaded: "Bild wurde hochgeladen.",
        generated: "Bild wurde erstellt.",
        saved: "Gespeichert.",
        saveDraft: "Zwischenspeichern",
        preview: "Vorschau Ihrer App",
        brandingRequired:
            "Bitte ergänzen Sie die Beschreibung, das Logo und das Startbild.",
        looksGood: "Ja, so passt es",
        checkPrompt: "Prompt vor der Erstellung prüfen",
        promptWarning:
            "Prüfen und korrigieren Sie den Prompt. Erst danach wird das Bildmodell gestartet.",
        generate: "Bild erstellen",
        generating: "Bild wird erstellt…",
    },
    en: {
        branding: "Brand & images",
        brandingIntro:
            "Manage your logo, app hero image and public presentation.",
        brandingOnboarding:
            "Answer a few questions, then upload your own images or prepare them with AI.",
        questionnaire: "About your business",
        businessDescription: "What does your business do?",
        customers: "Who are your customers?",
        visualStyle: "Desired look and style",
        avoid: "What must not appear in the image?",
        tagline: "Short headline",
        logo: "Logo / app icon",
        heroImage: "App hero image",
        upload: "Upload image",
        generateAi: "Create with AI",
        uploaded: "Image uploaded.",
        generated: "Image created.",
        saved: "Saved.",
        saveDraft: "Save draft",
        preview: "App preview",
        brandingRequired: "Please add a description, logo and hero image.",
        looksGood: "Yes, this fits",
        checkPrompt: "Check prompt before generation",
        promptWarning:
            "Review and correct the prompt. The image model starts only after confirmation.",
        generate: "Create image",
        generating: "Creating image…",
    },
    ru: {
        branding: "Оформление и картинки",
        brandingIntro: "Логотип, главное изображение и внешний вид приложения.",
        brandingOnboarding:
            "Коротко расскажите о фирме, затем загрузите свои изображения или подготовьте их с помощью ИИ.",
        questionnaire: "О вашей фирме",
        businessDescription: "Чем занимается ваша фирма?",
        customers: "Для каких клиентов вы работаете?",
        visualStyle: "Желаемый стиль и впечатление",
        avoid: "Чего не должно быть на изображениях?",
        tagline: "Короткий заголовок",
        logo: "Логотип / значок приложения",
        heroImage: "Главное изображение приложения",
        upload: "Загрузить своё",
        generateAi: "Создать с помощью ИИ",
        uploaded: "Изображение загружено.",
        generated: "Изображение создано.",
        saved: "Сохранено.",
        saveDraft: "Сохранить черновик",
        preview: "Так будет выглядеть приложение",
        brandingRequired: "Добавьте описание, логотип и главное изображение.",
        looksGood: "Да, так подходит",
        checkPrompt: "Проверьте промт перед созданием",
        promptWarning:
            "Проверьте и исправьте промт. Только после подтверждения запустится модель изображений.",
        generate: "Создать изображение",
        generating: "Изображение создаётся…",
    },
    uk: {
        branding: "Оформлення та зображення",
        brandingIntro: "Логотип, головне зображення та вигляд застосунку.",
        brandingOnboarding:
            "Коротко розкажіть про фірму, потім завантажте власні зображення або підготуйте їх за допомогою ШІ.",
        questionnaire: "Про вашу фірму",
        businessDescription: "Чим займається ваша фірма?",
        customers: "Для яких клієнтів ви працюєте?",
        visualStyle: "Бажаний стиль і враження",
        avoid: "Чого не повинно бути на зображеннях?",
        tagline: "Короткий заголовок",
        logo: "Логотип / значок застосунку",
        heroImage: "Головне зображення застосунку",
        upload: "Завантажити своє",
        generateAi: "Створити за допомогою ШІ",
        uploaded: "Зображення завантажено.",
        generated: "Зображення створено.",
        saved: "Збережено.",
        saveDraft: "Зберегти чернетку",
        preview: "Так виглядатиме застосунок",
        brandingRequired: "Додайте опис, логотип і головне зображення.",
        looksGood: "Так, усе підходить",
        checkPrompt: "Перевірте промт перед створенням",
        promptWarning:
            "Перевірте й виправте промт. Лише після підтвердження буде запущено модель зображень.",
        generate: "Створити зображення",
        generating: "Зображення створюється…",
    },
};

const serviceExtra: Record<MasterLocale, Record<string, string>> = {
    de: {
        serviceImage: "Leistungsbild",
        chooseImage: "Bild auswählen",
        removeImage: "Bild entfernen",
        serviceImageHint:
            "Das Bild erscheint bei der Leistung in der öffentlichen App. Uploads werden automatisch optimiert.",
    },
    en: {
        serviceImage: "Service image",
        chooseImage: "Choose image",
        removeImage: "Remove image",
        serviceImageHint:
            "This image appears with the service in the public app. Uploads are optimized automatically.",
    },
    ru: {
        serviceImage: "Изображение услуги",
        chooseImage: "Выбрать изображение",
        removeImage: "Удалить изображение",
        serviceImageHint:
            "Изображение будет показано у услуги в публичном приложении. Загруженный файл оптимизируется автоматически.",
    },
    uk: {
        serviceImage: "Зображення послуги",
        chooseImage: "Обрати зображення",
        removeImage: "Видалити зображення",
        serviceImageHint:
            "Зображення буде показано біля послуги у публічному застосунку. Завантажений файл оптимізується автоматично.",
    },
};

export const brandingServiceText = (locale: MasterLocale, key: string) =>
    brandingExtra[locale]?.[key] || serviceExtra[locale]?.[key];

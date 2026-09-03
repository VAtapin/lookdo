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
        targetCustomers: "Für welche Kunden arbeiten Sie?",
        visualStyle: "Gewünschte Wirkung und Stil",
        avoid: "Was darf nicht im Bild erscheinen?",
        tagline: "Kurze Überschrift",
        logo: "Logo / App-Symbol",
        horizontalLogo: "Breites Logo für den App-Kopf",
        localizedTexts: "Öffentliche Texte nach Sprache",
        heroTexts: "Texte auf der Startseite",
        heroTextsHint:
            "Diese Texte stehen direkt auf dem großen Startbild. Änderungen überschreiben nur die gewählte Sprache.",
        heroEyebrow: "Kurzbezeichnung über der Überschrift",
        heroTitle: "Hauptüberschrift",
        heroText: "Kurze Erklärung",
        heroAction: "Text der Hauptschaltfläche",
        serviceModes: "Wo wird die Leistung erbracht?",
        serviceModeWorkshop: "Im Studio / in der Werkstatt",
        serviceModeOnSite: "Mobil beim Kunden",
        heroImage: "Startbild der App",
        upload: "Eigenes Bild laden",
        generateAi: "Mit KI erstellen",
        preparingPrompt: "Prompt wird vorbereitet…",
        uploaded: "Bild wurde hochgeladen.",
        generated: "Bild wurde erstellt.",
        saved: "Gespeichert.",
        saveDraft: "Zwischenspeichern",
        saveBranding: "Auftritt speichern",
        whatsappHelp:
            "Geben Sie die WhatsApp-Nummer international ein. LOOKDO erstellt den wa.me-Link automatisch.",
        maxHelp:
            "Öffnen Sie Ihr MAX-Profil, wählen Sie Einladen/Teilen und fügen Sie den kopierten max.ru/u-Link ein.",
        telegramHelp:
            "Geben Sie Ihren öffentlichen @Benutzernamen oder den vollständigen t.me-Link ein.",
        viberHelp:
            "Geben Sie die Viber-Nummer international ein. LOOKDO erstellt den Link automatisch.",
        preview: "Vorschau Ihrer App",
        brandingRequired:
            "Bitte ergänzen Sie die Beschreibung, das Logo und das Startbild.",
        looksGood: "Ja, so passt es",
        checkPrompt: "Prompt vor der Erstellung prüfen",
        promptWarning:
            "Prüfen und korrigieren Sie den Prompt. Erst danach wird das Bildmodell gestartet.",
        generate: "Bild erstellen",
        generating: "Bild wird erstellt…",
        designServiceTitle: "Sie wünschen ein individuelles App-Design?",
        designServicePaid:
            "Die LOOKDO Designer können Layout, Stil und Darstellung individuell anpassen. Die Umsetzung wird separat kalkuliert.",
        designServiceBusiness:
            "Im Business-Tarif ist die persönliche Designberatung enthalten. Die gewünschte Umsetzung wird danach transparent kalkuliert.",
        contactLookdoDesigners: "LOOKDO Designer kontaktieren",
        designRequestSubject: "Anfrage für individuelles LOOKDO Design",
        designRequestGreeting:
            "Hallo LOOKDO Design-Team, ich wünsche Änderungen am Design meiner App.",
        designRequestQuestions: "Meine Wünsche und Beispiele:",
    },
    en: {
        branding: "Brand & images",
        brandingIntro:
            "Manage your logo, app hero image and public presentation.",
        brandingOnboarding:
            "Answer a few questions, then upload your own images or prepare them with AI.",
        questionnaire: "About your business",
        businessDescription: "What does your business do?",
        targetCustomers: "Who are your customers?",
        visualStyle: "Desired look and style",
        avoid: "What must not appear in the image?",
        tagline: "Short headline",
        logo: "Logo / app icon",
        horizontalLogo: "Wide logo for the app header",
        localizedTexts: "Public text by language",
        heroTexts: "Home screen text",
        heroTextsHint:
            "These texts appear directly on the large hero image. Changes affect only the selected language.",
        heroEyebrow: "Label above the heading",
        heroTitle: "Main heading",
        heroText: "Short explanation",
        heroAction: "Main button text",
        serviceModes: "Where is the service provided?",
        serviceModeWorkshop: "At the studio / workshop",
        serviceModeOnSite: "At the customer's location",
        heroImage: "App hero image",
        upload: "Upload image",
        generateAi: "Create with AI",
        preparingPrompt: "Preparing prompt…",
        uploaded: "Image uploaded.",
        generated: "Image created.",
        saved: "Saved.",
        saveDraft: "Save draft",
        saveBranding: "Save appearance",
        whatsappHelp:
            "Enter the WhatsApp number in international format. LOOKDO creates the wa.me link automatically.",
        maxHelp:
            "Open your MAX profile, choose Invite/Share, and paste the copied max.ru/u link.",
        telegramHelp: "Enter your public @username or the complete t.me link.",
        viberHelp:
            "Enter the Viber number in international format. LOOKDO creates the link automatically.",
        preview: "App preview",
        brandingRequired: "Please add a description, logo and hero image.",
        looksGood: "Yes, this fits",
        checkPrompt: "Check prompt before generation",
        promptWarning:
            "Review and correct the prompt. The image model starts only after confirmation.",
        generate: "Create image",
        generating: "Creating image…",
        designServiceTitle: "Need a custom app design?",
        designServicePaid:
            "LOOKDO designers can tailor the layout, style and presentation. Implementation is quoted separately.",
        designServiceBusiness:
            "A personal design consultation is included with Business. The requested implementation is then quoted transparently.",
        contactLookdoDesigners: "Contact LOOKDO designers",
        designRequestSubject: "Custom LOOKDO design request",
        designRequestGreeting:
            "Hello LOOKDO design team, I would like changes to my app design.",
        designRequestQuestions: "My wishes and examples:",
    },
    ru: {
        branding: "Оформление и картинки",
        brandingIntro: "Логотип, главное изображение и внешний вид приложения.",
        brandingOnboarding:
            "Коротко расскажите о фирме, затем загрузите свои изображения или подготовьте их с помощью ИИ.",
        questionnaire: "О вашей фирме",
        businessDescription: "Чем занимается ваша фирма?",
        targetCustomers: "Для каких клиентов вы работаете?",
        visualStyle: "Желаемый стиль и впечатление",
        avoid: "Чего не должно быть на изображениях?",
        tagline: "Короткий заголовок",
        logo: "Логотип / значок приложения",
        horizontalLogo: "Широкий логотип для шапки приложения",
        localizedTexts: "Публичные тексты по языкам",
        heroTexts: "Тексты главного экрана",
        heroTextsHint:
            "Эти тексты находятся прямо на большом главном изображении. Изменения затронут только выбранный язык.",
        heroEyebrow: "Надпись над заголовком",
        heroTitle: "Главный заголовок",
        heroText: "Короткое пояснение",
        heroAction: "Текст главной кнопки",
        serviceModes: "Где оказывается услуга?",
        serviceModeWorkshop: "В студии / мастерской",
        serviceModeOnSite: "С выездом к клиенту",
        heroImage: "Главное изображение приложения",
        upload: "Загрузить своё",
        generateAi: "Создать с помощью ИИ",
        preparingPrompt: "Подготавливаем промт…",
        uploaded: "Изображение загружено.",
        generated: "Изображение создано.",
        saved: "Сохранено.",
        saveDraft: "Сохранить черновик",
        saveBranding: "Сохранить оформление",
        whatsappHelp:
            "Введите номер WhatsApp в международном формате. LOOKDO сам создаст ссылку wa.me.",
        maxHelp:
            "Откройте свой профиль MAX, выберите «Пригласить/Поделиться» и вставьте скопированную ссылку max.ru/u.",
        telegramHelp: "Введите публичное имя @username или полную ссылку t.me.",
        viberHelp:
            "Введите номер Viber в международном формате. LOOKDO сам создаст ссылку.",
        preview: "Так будет выглядеть приложение",
        brandingRequired: "Добавьте описание, логотип и главное изображение.",
        looksGood: "Да, так подходит",
        checkPrompt: "Проверьте промт перед созданием",
        promptWarning:
            "Проверьте и исправьте промт. Только после подтверждения запустится модель изображений.",
        generate: "Создать изображение",
        generating: "Изображение создаётся…",
        designServiceTitle: "Нужен индивидуальный дизайн приложения?",
        designServicePaid:
            "Дизайнеры LOOKDO могут серьёзно изменить компоновку, стиль и оформление. Работа рассчитывается и оплачивается отдельно.",
        designServiceBusiness:
            "В тариф Business входит персональная консультация дизайнера. Сама выбранная доработка рассчитывается отдельно и заранее согласовывается.",
        contactLookdoDesigners: "Обратиться к дизайнерам LOOKDO",
        designRequestSubject: "Заявка на индивидуальный дизайн LOOKDO",
        designRequestGreeting:
            "Здравствуйте! Я хочу изменить дизайн своего приложения LOOKDO.",
        designRequestQuestions: "Мои пожелания и примеры:",
    },
    uk: {
        branding: "Оформлення та зображення",
        brandingIntro: "Логотип, головне зображення та вигляд застосунку.",
        brandingOnboarding:
            "Коротко розкажіть про фірму, потім завантажте власні зображення або підготуйте їх за допомогою ШІ.",
        questionnaire: "Про вашу фірму",
        businessDescription: "Чим займається ваша фірма?",
        targetCustomers: "Для яких клієнтів ви працюєте?",
        visualStyle: "Бажаний стиль і враження",
        avoid: "Чого не повинно бути на зображеннях?",
        tagline: "Короткий заголовок",
        logo: "Логотип / значок застосунку",
        horizontalLogo: "Широкий логотип для шапки застосунку",
        localizedTexts: "Публічні тексти за мовами",
        heroTexts: "Тексти головного екрана",
        heroTextsHint:
            "Ці тексти розміщені безпосередньо на великому головному зображенні. Зміни стосуються лише вибраної мови.",
        heroEyebrow: "Напис над заголовком",
        heroTitle: "Головний заголовок",
        heroText: "Коротке пояснення",
        heroAction: "Текст головної кнопки",
        serviceModes: "Де надається послуга?",
        serviceModeWorkshop: "У студії / майстерні",
        serviceModeOnSite: "З виїздом до клієнта",
        heroImage: "Головне зображення застосунку",
        upload: "Завантажити своє",
        generateAi: "Створити за допомогою ШІ",
        preparingPrompt: "Готуємо промт…",
        uploaded: "Зображення завантажено.",
        generated: "Зображення створено.",
        saved: "Збережено.",
        saveDraft: "Зберегти чернетку",
        saveBranding: "Зберегти оформлення",
        whatsappHelp:
            "Введіть номер WhatsApp у міжнародному форматі. LOOKDO сам створить посилання wa.me.",
        maxHelp:
            "Відкрийте свій профіль MAX, виберіть «Запросити/Поділитися» та вставте скопійоване посилання max.ru/u.",
        telegramHelp:
            "Введіть публічне ім’я @username або повне посилання t.me.",
        viberHelp:
            "Введіть номер Viber у міжнародному форматі. LOOKDO сам створить посилання.",
        preview: "Так виглядатиме застосунок",
        brandingRequired: "Додайте опис, логотип і головне зображення.",
        looksGood: "Так, усе підходить",
        checkPrompt: "Перевірте промт перед створенням",
        promptWarning:
            "Перевірте й виправте промт. Лише після підтвердження буде запущено модель зображень.",
        generate: "Створити зображення",
        generating: "Зображення створюється…",
        designServiceTitle: "Потрібен індивідуальний дизайн застосунку?",
        designServicePaid:
            "Дизайнери LOOKDO можуть суттєво змінити компонування, стиль і оформлення. Робота розраховується й оплачується окремо.",
        designServiceBusiness:
            "До тарифу Business входить персональна консультація дизайнера. Обрана доробка розраховується окремо та погоджується заздалегідь.",
        contactLookdoDesigners: "Звернутися до дизайнерів LOOKDO",
        designRequestSubject: "Заявка на індивідуальний дизайн LOOKDO",
        designRequestGreeting:
            "Вітаю! Я хочу змінити дизайн свого застосунку LOOKDO.",
        designRequestQuestions: "Мої побажання та приклади:",
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

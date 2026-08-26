import { computed, ref } from 'vue';
import de from './locales/de';
import en from './locales/en';
import ru from './locales/ru';
import uk from './locales/uk';

export type Locale = 'de' | 'en' | 'ru' | 'uk';
const storedLocale = localStorage.getItem('lookdo-locale');
export const locale = ref<Locale>((['de', 'en', 'ru', 'uk'].includes(storedLocale || '') ? storedLocale : 'de') as Locale);

const messages: Record<Locale, Record<string, string>> = { de, en, ru, uk };

Object.assign(messages.ru, {
    noTemplate: 'Точного совпадения нет — выбран универсальный шаблон. Его можно изменить позже.',
    heroTagline: 'СМОТРИ. ДЕЛАЙ.',
    heroBenefitBrand: 'Ваш бренд и домен', heroBenefitFast: 'Быстрый старт за 15 минут', heroBenefitSafe: 'Данные в безопасности',
    howShow: 'Клиент показывает задачу', howPhone: 'Оставляет телефон', howReceive: 'Вы получаете заявку', howReply: 'Отвечаете и договариваетесь',
    audienceAuto: 'Автосервис', audienceDoors: 'Установка дверей', audienceConstruction: 'Ремонт и строительство', audienceAppliance: 'Бытовая техника', audienceFurniture: 'Мебель', audienceGarden: 'Сад и участок', audienceCleaning: 'Уборка',
    benefitDomainTitle: 'Собственный домен', benefitMediaTitle: 'Фото и видео заявки', benefitPushTitle: 'Push-уведомления', benefitReviewTitle: 'Отзывы и До/После',
    audienceBrows:'Уход за бровями', audienceBeauty:'Красота и уход', bookingTitle:'Онлайн-запись', bookingText:'Услуги, свободное время, напоминания и повторные визиты.', socialTitle:'Контент и связь', socialText:'Подготовка публикаций и переход в реально подключённые каналы.', appAddress:'Адрес приложения', appAddressHelp:'Необязательно. Если оставить пустым, LOOKDO создаст адрес из названия бизнеса.', heroProduct:'Платформа визуальных заявок для сервисного бизнеса',
    check:'Проверить', describeActivityFirst:'Сначала опишите деятельность.', activityAutoHint:'Напишите не менее четырёх слов — LOOKDO сразу начнёт подбор.', activityWaiting:'Готовим подбор шаблона…', templateFound:'Подходящие шаблоны найдены. Выберите вариант.', checkAgain:'Проверить ещё раз', showTemplate:'Показать приложение', templatePreview:'Предпросмотр приложения', templateReady:'Визуальная заявка', yourTemplateReady:'Вот как будет выглядеть ваше приложение', templateChangesLive:'Изображение и цвета меняются вместе с выбранным шаблоном.', templateConfirmText:'Это стартовый экран выбранного процесса. Логотип, контакты и тексты вы сможете настроить после регистрации.', changeActivity:'Изменить деятельность', currency:'Валюта', perYear:'/ год', save:'Экономия',
});
Object.assign(messages.de, {
    noTemplate: 'Kein exakter Treffer – die universelle Vorlage wurde gewählt. Sie kann später geändert werden.',
    heroTagline: 'SCHAU. MACH.',
    heroBenefitBrand: 'Ihre Marke und Domain', heroBenefitFast: 'Start in 15 Minuten', heroBenefitSafe: 'Sichere Daten',
    howShow: 'Kunde zeigt die Aufgabe', howPhone: 'Hinterlässt die Nummer', howReceive: 'Sie erhalten die Anfrage', howReply: 'Sie antworten direkt',
    audienceAuto: 'Autoservice', audienceDoors: 'Türmontage', audienceConstruction: 'Bau & Renovierung', audienceAppliance: 'Haushaltsgeräte', audienceFurniture: 'Möbel', audienceGarden: 'Garten', audienceCleaning: 'Reinigung',
    benefitDomainTitle: 'Eigene Domain', benefitMediaTitle: 'Foto- und Videoanfragen', benefitPushTitle: 'Push-Benachrichtigungen', benefitReviewTitle: 'Bewertungen & Vorher/Nachher',
    audienceBrows:'Augenbrauenpflege', audienceBeauty:'Beauty & Pflege', bookingTitle:'Online-Termine', bookingText:'Leistungen, freie Zeiten, Erinnerungen und Wiederbesuche.', socialTitle:'Inhalte & Kontakt', socialText:'Beiträge vorbereiten und zu tatsächlich verbundenen Kanälen wechseln.', appAddress:'App-Adresse', appAddressHelp:'Optional. Ohne Eingabe erzeugt LOOKDO die Adresse aus dem Betriebsnamen.', heroProduct:'Plattform für visuelle Anfragen im Servicegeschäft',
    check:'Prüfen', describeActivityFirst:'Beschreiben Sie zuerst Ihre Tätigkeit.', activityAutoHint:'Schreiben Sie mindestens vier Wörter – LOOKDO startet dann automatisch.', activityWaiting:'Vorlagen werden vorbereitet…', templateFound:'Passende Vorlagen wurden gefunden. Bitte wählen Sie.', checkAgain:'Erneut prüfen', showTemplate:'App anzeigen', templatePreview:'App-Vorschau', templateReady:'Visuelle Anfrage', yourTemplateReady:'So wird Ihre App aussehen', templateChangesLive:'Bild und Farben folgen der ausgewählten Vorlage.', templateConfirmText:'Dies ist der Start der gewählten Vorlage. Logo, Kontaktdaten und Texte können Sie nach der Registrierung anpassen.', changeActivity:'Tätigkeit ändern', currency:'Währung', perYear:'/ Jahr', save:'Sie sparen',
});
Object.assign(messages.en, {
    noTemplate: 'No exact match — the universal template is selected. You can change it later.',
    heroTagline: 'LOOK. DO.',
    heroBenefitBrand: 'Your brand and domain', heroBenefitFast: 'Launch in 15 minutes', heroBenefitSafe: 'Secure data',
    howShow: 'Customer shows the task', howPhone: 'Leaves a phone number', howReceive: 'You receive the request', howReply: 'You reply and agree',
    audienceAuto: 'Auto service', audienceDoors: 'Door installation', audienceConstruction: 'Renovation & construction', audienceAppliance: 'Appliances', audienceFurniture: 'Furniture', audienceGarden: 'Garden', audienceCleaning: 'Cleaning',
    benefitDomainTitle: 'Custom domain', benefitMediaTitle: 'Photo and video requests', benefitPushTitle: 'Push notifications', benefitReviewTitle: 'Reviews & Before/After',
    audienceBrows:'Brow care', audienceBeauty:'Beauty & care', bookingTitle:'Online booking', bookingText:'Services, available times, reminders and repeat visits.', socialTitle:'Content & contact', socialText:'Prepare posts and open channels the business actually connected.', appAddress:'App address', appAddressHelp:'Optional. If left blank, LOOKDO creates it from the business name.', heroProduct:'The visual request platform for service businesses',
    check:'Check', describeActivityFirst:'Describe your activity first.', activityAutoHint:'Write at least four words and LOOKDO will start matching automatically.', activityWaiting:'Preparing template matches…', templateFound:'Matching templates found. Choose one.', checkAgain:'Check again', showTemplate:'Show my app', templatePreview:'App preview', templateReady:'Visual request', yourTemplateReady:'This is how your app will look', templateChangesLive:'The image and colours follow the selected template.', templateConfirmText:'This is the start screen for the selected process. You can adjust the logo, contact details and copy after registration.', changeActivity:'Change activity', currency:'Currency', perYear:'/ year', save:'Save',
});

Object.assign(messages.uk, {
    heroProduct:'Платформа візуальних заявок для сервісного бізнесу', check:'Перевірити', describeActivityFirst:'Спочатку опишіть діяльність.', activityAutoHint:'Напишіть щонайменше чотири слова — LOOKDO одразу почне підбір.', activityWaiting:'Готуємо підбір шаблону…', templateFound:'Відповідні шаблони знайдено. Оберіть варіант.', checkAgain:'Перевірити ще раз', showTemplate:'Показати застосунок', templatePreview:'Перегляд застосунку', templateReady:'Візуальна заявка', yourTemplateReady:'Ось як виглядатиме ваш застосунок', templateChangesLive:'Зображення та кольори змінюються разом із вибраним шаблоном.', templateConfirmText:'Це стартовий екран вибраного процесу. Логотип, контакти й тексти можна налаштувати після реєстрації.', changeActivity:'Змінити діяльність', currency:'Валюта', perYear:'/ рік', save:'Економія',
});

messages.ru.whyTitle = 'Получайте заявки, ведите запись и общайтесь с клиентами — в одном приложении';
messages.de.whyTitle = 'Anfragen erhalten, Termine verwalten und mit Kunden kommunizieren — in einer App';
messages.en.whyTitle = 'Receive requests, manage bookings and talk to customers — all in one app';
messages.uk.whyTitle = 'Отримуйте заявки, ведіть запис і спілкуйтеся з клієнтами — в одному застосунку';

export const t = (key: string) => computed(() => messages[locale.value][key] || key);
export function tr(key: string): string { return messages[locale.value][key] || key; }
export function setLocale(value: Locale) { locale.value=value;localStorage.setItem('lookdo-locale',value);document.documentElement.lang=value; }

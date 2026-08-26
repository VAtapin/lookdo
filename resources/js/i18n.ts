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
    heroTagline: 'СМОТРИ. ДЕЛАЙ.', heroProduct: 'Ваше приложение для мастеров — без App Store',
    heroBenefitBrand: 'Ваш бренд и домен', heroBenefitFast: 'Быстрый старт за 15 минут', heroBenefitSafe: 'Данные в безопасности',
    howShow: 'Клиент показывает задачу', howPhone: 'Оставляет телефон', howReceive: 'Вы получаете заявку', howReply: 'Отвечаете и договариваетесь',
    audienceAuto: 'Автосервис', audienceDoors: 'Установка дверей', audienceConstruction: 'Ремонт и строительство', audienceAppliance: 'Бытовая техника', audienceFurniture: 'Мебель', audienceGarden: 'Сад и участок', audienceCleaning: 'Уборка',
    benefitDomainTitle: 'Собственный домен', benefitMediaTitle: 'Фото и видео заявки', benefitPushTitle: 'Push-уведомления', benefitReviewTitle: 'Отзывы и До/После',
    audienceBrows:'Уход за бровями', audienceBeauty:'Красота и уход', bookingTitle:'Онлайн-запись', bookingText:'Услуги, свободное время, напоминания и повторные визиты.', socialTitle:'Контент и связь', socialText:'Подготовка публикаций и переход в реально подключённые каналы.', appAddress:'Адрес приложения', appAddressHelp:'Необязательно. Если оставить пустым, LOOKDO создаст адрес из названия бизнеса.',
});
Object.assign(messages.de, {
    noTemplate: 'Kein exakter Treffer – die universelle Vorlage wurde gewählt. Sie kann später geändert werden.',
    heroTagline: 'SCHAU. MACH.', heroProduct: 'Ihre App für Handwerker — ohne App Store',
    heroBenefitBrand: 'Ihre Marke und Domain', heroBenefitFast: 'Start in 15 Minuten', heroBenefitSafe: 'Sichere Daten',
    howShow: 'Kunde zeigt die Aufgabe', howPhone: 'Hinterlässt die Nummer', howReceive: 'Sie erhalten die Anfrage', howReply: 'Sie antworten direkt',
    audienceAuto: 'Autoservice', audienceDoors: 'Türmontage', audienceConstruction: 'Bau & Renovierung', audienceAppliance: 'Haushaltsgeräte', audienceFurniture: 'Möbel', audienceGarden: 'Garten', audienceCleaning: 'Reinigung',
    benefitDomainTitle: 'Eigene Domain', benefitMediaTitle: 'Foto- und Videoanfragen', benefitPushTitle: 'Push-Benachrichtigungen', benefitReviewTitle: 'Bewertungen & Vorher/Nachher',
    audienceBrows:'Augenbrauenpflege', audienceBeauty:'Beauty & Pflege', bookingTitle:'Online-Termine', bookingText:'Leistungen, freie Zeiten, Erinnerungen und Wiederbesuche.', socialTitle:'Inhalte & Kontakt', socialText:'Beiträge vorbereiten und zu tatsächlich verbundenen Kanälen wechseln.', appAddress:'App-Adresse', appAddressHelp:'Optional. Ohne Eingabe erzeugt LOOKDO die Adresse aus dem Betriebsnamen.',
});
Object.assign(messages.en, {
    noTemplate: 'No exact match — the universal template is selected. You can change it later.',
    heroTagline: 'LOOK. DO.', heroProduct: 'Your app for service pros — no App Store',
    heroBenefitBrand: 'Your brand and domain', heroBenefitFast: 'Launch in 15 minutes', heroBenefitSafe: 'Secure data',
    howShow: 'Customer shows the task', howPhone: 'Leaves a phone number', howReceive: 'You receive the request', howReply: 'You reply and agree',
    audienceAuto: 'Auto service', audienceDoors: 'Door installation', audienceConstruction: 'Renovation & construction', audienceAppliance: 'Appliances', audienceFurniture: 'Furniture', audienceGarden: 'Garden', audienceCleaning: 'Cleaning',
    benefitDomainTitle: 'Custom domain', benefitMediaTitle: 'Photo and video requests', benefitPushTitle: 'Push notifications', benefitReviewTitle: 'Reviews & Before/After',
    audienceBrows:'Brow care', audienceBeauty:'Beauty & care', bookingTitle:'Online booking', bookingText:'Services, available times, reminders and repeat visits.', socialTitle:'Content & contact', socialText:'Prepare posts and open channels the business actually connected.', appAddress:'App address', appAddressHelp:'Optional. If left blank, LOOKDO creates it from the business name.',
});

export const t = (key: string) => computed(() => messages[locale.value][key] || key);
export function tr(key: string): string { return messages[locale.value][key] || key; }
export function setLocale(value: Locale) { locale.value=value;localStorage.setItem('lookdo-locale',value);document.documentElement.lang=value; }

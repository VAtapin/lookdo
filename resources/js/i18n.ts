import { computed, ref } from 'vue';
import de from './locales/de';
import en from './locales/en';
import ru from './locales/ru';

export type Locale = 'de' | 'en' | 'ru';
export const locale = ref<Locale>((localStorage.getItem('lookdo-locale') as Locale) || 'de');

const messages: Record<Locale, Record<string, string>> = { de, en, ru };

Object.assign(messages.ru, {
    noTemplate: 'Точного совпадения нет — выбран универсальный шаблон. Его можно изменить позже.',
    heroTagline: 'СМОТРИ. ДЕЛАЙ.', heroProduct: 'Ваше приложение для мастеров — без App Store',
    heroBenefitBrand: 'Ваш бренд и домен', heroBenefitFast: 'Быстрый старт за 15 минут', heroBenefitSafe: 'Данные в безопасности',
    howShow: 'Клиент показывает задачу', howPhone: 'Оставляет телефон', howReceive: 'Вы получаете заявку', howReply: 'Отвечаете и договариваетесь',
    audienceAuto: 'Автосервис', audienceDoors: 'Установка дверей', audienceAppliance: 'Бытовая техника', audienceFurniture: 'Мебель', audienceGarden: 'Сад и участок', audienceCleaning: 'Уборка',
    benefitDomainTitle: 'Собственный домен', benefitMediaTitle: 'Фото и видео заявки', benefitPushTitle: 'Push-уведомления', benefitReviewTitle: 'Отзывы и До/После',
});
Object.assign(messages.de, {
    noTemplate: 'Kein exakter Treffer – die universelle Vorlage wurde gewählt. Sie kann später geändert werden.',
    heroTagline: 'SCHAU. MACH.', heroProduct: 'Ihre App für Handwerker — ohne App Store',
    heroBenefitBrand: 'Ihre Marke und Domain', heroBenefitFast: 'Start in 15 Minuten', heroBenefitSafe: 'Sichere Daten',
    howShow: 'Kunde zeigt die Aufgabe', howPhone: 'Hinterlässt die Nummer', howReceive: 'Sie erhalten die Anfrage', howReply: 'Sie antworten direkt',
    audienceAuto: 'Autoservice', audienceDoors: 'Türmontage', audienceAppliance: 'Haushaltsgeräte', audienceFurniture: 'Möbel', audienceGarden: 'Garten', audienceCleaning: 'Reinigung',
    benefitDomainTitle: 'Eigene Domain', benefitMediaTitle: 'Foto- und Videoanfragen', benefitPushTitle: 'Push-Benachrichtigungen', benefitReviewTitle: 'Bewertungen & Vorher/Nachher',
});
Object.assign(messages.en, {
    noTemplate: 'No exact match — the universal template is selected. You can change it later.',
    heroTagline: 'LOOK. DO.', heroProduct: 'Your app for service pros — no App Store',
    heroBenefitBrand: 'Your brand and domain', heroBenefitFast: 'Launch in 15 minutes', heroBenefitSafe: 'Secure data',
    howShow: 'Customer shows the task', howPhone: 'Leaves a phone number', howReceive: 'You receive the request', howReply: 'You reply and agree',
    audienceAuto: 'Auto service', audienceDoors: 'Door installation', audienceAppliance: 'Appliances', audienceFurniture: 'Furniture', audienceGarden: 'Garden', audienceCleaning: 'Cleaning',
    benefitDomainTitle: 'Custom domain', benefitMediaTitle: 'Photo and video requests', benefitPushTitle: 'Push notifications', benefitReviewTitle: 'Reviews & Before/After',
});

export const t = (key: string) => computed(() => messages[locale.value][key] || key);
export function tr(key: string): string { return messages[locale.value][key] || key; }
export function setLocale(value: Locale) { locale.value=value;localStorage.setItem('lookdo-locale',value);document.documentElement.lang=value; }

import { computed, ref } from 'vue';
import de from './locales/de';
import en from './locales/en';
import ru from './locales/ru';

export type Locale = 'de' | 'en' | 'ru';
export const locale = ref<Locale>((localStorage.getItem('lookdo-locale') as Locale) || 'de');

const messages: Record<Locale, Record<string, string>> = { de, en, ru };

export const t = (key: string) => computed(() => messages[locale.value][key] || key);
export function tr(key: string): string { return messages[locale.value][key] || key; }
export function setLocale(value: Locale) { locale.value=value;localStorage.setItem('lookdo-locale',value);document.documentElement.lang=value; }

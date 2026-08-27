import de from './locales/de';
import en from './locales/en';
import ru from './locales/ru';
import uk from './locales/uk';

export type TenantLocale = 'de' | 'en' | 'ru' | 'uk';

const copy = { de, en, ru, uk } as const;

export function appCopy(locale: TenantLocale) {
  return copy[locale] || copy.de;
}

<?php

namespace App\Support;

final class LegalContentSanitizer
{
    public static function clean(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $clean = preg_replace('~\{\{\s*dispute_statement\s*\}\}~iu', '', $html) ?? $html;
        $clean = str_replace([
            ' Für Kündigung und Widerruf beachten Sie bitte zusätzlich die Seite „Widerruf und Kündigung“.',
            ' Please also see “Withdrawal and cancellation”.',
            ' Для прекращения подписки и отзыва договора также ознакомьтесь со страницей «Отказ от договора и прекращение».',
            ' Для припинення підписки та відкликання договору також перегляньте сторінку «Відмова від договору та припинення».',
        ], '', $clean);

        $patterns = [
            '~<p\b[^>]*class\s*=\s*["\'][^"\']*\blegal-notice\b[^"\']*["\'][^>]*>.*?</p>\s*~isu',
            '~<h([1-6])\b[^>]*>\s*(?:Hinweis zur Streitbeilegung|Verbraucherstreitbeilegung|Consumer dispute resolution|Урегулирование потребительских споров|Врегулювання споживчих спорів|Verantwortung für Inhalte und Links|Haftung für Links|Haftung für Inhalte und Links|Content and external links|Liability for links|Ответственность за содержимое и ссылки|Відповідальність за вміст і посилання)\s*</h\1>\s*(?:<p\b[^>]*>.*?</p>\s*)?~isu',
            '~<p\b[^>]*>(?:(?!</p>).)*(?:Verbraucherschlichtungsstelle|OS[\s-]*Plattform|Online[\s-]*Streitbeilegung|ec\.europa\.eu/consumers/odr|consumer-redress\.ec\.europa\.eu)(?:(?!</p>).)*</p>\s*~isu',
            '~<a\b[^>]*href\s*=\s*["\'][^"\']*(?:ec\.europa\.eu/consumers/odr|consumer-redress\.ec\.europa\.eu)[^"\']*["\'][^>]*>.*?</a>~isu',
        ];

        return trim(preg_replace($patterns, '', $clean) ?? $clean);
    }
}

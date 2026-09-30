import { usePage } from '@inertiajs/react';
import type { Replacements } from '@/lib/i18n';
import { translate } from '@/lib/i18n';
import type { TextDirection } from '@/types';

export type TranslateFn = (key: string, replacements?: Replacements) => string;

/**
 * Translations for the active locale, shared by HandleInertiaRequests.
 *
 *   const { t } = useTranslation();
 *   t('Hello :name', { name });
 *   t(':count stamps left', { count }); // plural via Laravel's `a|b` syntax
 */
export function useTranslation(): {
    t: TranslateFn;
    locale: string;
    dir: TextDirection;
} {
    const { locale, dir, translations } = usePage().props;

    const t: TranslateFn = (key, replacements) =>
        translate(translations, locale, key, replacements);

    return { t, locale, dir };
}

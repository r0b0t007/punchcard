import { router, usePage } from '@inertiajs/react';
import { DirectionProvider } from '@radix-ui/react-direction';
import type { ReactNode } from 'react';
import { useEffect, useLayoutEffect, useMemo, useRef } from 'react';
import { createI18n, I18nContext } from '@/hooks/use-translation';

/**
 * Outermost persistent layout of every page (see app.tsx). It reads the
 * locale and translations from the page props *during render*, so a page
 * never shows the previous language after a switch or a login, and provides
 * them to useTranslation(). Also keeps <html lang dir> in sync and feeds
 * Radix's DirectionProvider, which does not read <html dir>.
 *
 * Outside it (component tests, previews) useTranslation() falls back to the
 * English keys.
 */
export default function I18nLayout({ children }: { children: ReactNode }) {
    const { locale, dir, translations } = usePage().props;

    // Stable while the locale is unchanged (the client reuses the cached once
    // prop), so t() keeps its identity across visits.
    const i18n = useMemo(
        () => createI18n(locale, dir, translations ?? {}),
        [locale, dir, translations],
    );

    useLayoutEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
    }, [locale, dir]);

    // A visit without its translations (e.g. a history entry whose once prop
    // is gone) renders the English keys; fetch the set once per locale.
    const refetched = useRef(new Set<string>());

    useEffect(() => {
        if (!translations && !refetched.current.has(locale)) {
            refetched.current.add(locale);
            router.reload({ only: ['translations'] });
        }
    }, [locale, translations]);

    return (
        <I18nContext value={i18n}>
            <DirectionProvider dir={dir}>{children}</DirectionProvider>
        </I18nContext>
    );
}

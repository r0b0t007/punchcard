import { router } from '@inertiajs/react';
import { DirectionProvider } from '@radix-ui/react-direction';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { createI18n, I18nContext } from '@/hooks/use-translation';
import type { Translations } from '@/lib/i18n';
import type { TextDirection } from '@/types';

type LocaleProps = {
    locale: string;
    dir: TextDirection;
    translations?: Translations;
};

/**
 * Provides t()/tn() and the text direction to the whole app, including shadcn
 * primitives and anything rendered outside a page (toasts, providers).
 *
 * Starts from the initial Inertia page, then follows the router: `success`
 * covers every completed visit, including the language switcher's same-URL
 * redirect (a history replace, so no `navigate`); `navigate` covers
 * back/forward, which makes no request. Also keeps <html lang dir> in sync and
 * feeds Radix's DirectionProvider, which does not read <html dir>.
 */
export default function I18nProvider({
    initialProps,
    children,
}: {
    initialProps: LocaleProps;
    children: ReactNode;
}) {
    const [state, setState] = useState(() => ({
        locale: initialProps.locale,
        dir: initialProps.dir,
        translations: initialProps.translations ?? {},
    }));

    useEffect(() => {
        const sync = ({ props }: { props: LocaleProps }) => {
            document.documentElement.lang = props.locale;
            document.documentElement.dir = props.dir;
            setState((previous) => ({
                locale: props.locale,
                dir: props.dir,
                // The client merges cached once props back in; if a visit ever
                // arrives without them, keep the set only for the same locale.
                translations:
                    props.translations ??
                    (props.locale === previous.locale
                        ? previous.translations
                        : {}),
            }));
        };

        const stopSuccess = router.on('success', (event) =>
            sync(event.detail.page),
        );
        const stopNavigate = router.on('navigate', (event) =>
            sync(event.detail.page),
        );

        return () => {
            stopSuccess();
            stopNavigate();
        };
    }, []);

    const i18n = useMemo(
        () => createI18n(state.locale, state.dir, state.translations),
        [state],
    );

    return (
        <I18nContext value={i18n}>
            <DirectionProvider dir={state.dir}>{children}</DirectionProvider>
        </I18nContext>
    );
}

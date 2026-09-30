import type { ReactNode } from 'react';
import { createContext, createElement, Fragment, useContext } from 'react';
import type { Replacements, Translations } from '@/lib/i18n';
import { interpolate, translate } from '@/lib/i18n';
import type { TextDirection } from '@/types';

export type TranslateFn = (key: string, replacements?: Replacements) => string;

/** Like t(), but :placeholders in `nodes` can be React elements (links, buttons). */
export type TranslateNodesFn = (
    key: string,
    nodes: Record<string, ReactNode>,
    replacements?: Replacements,
) => ReactNode;

export type I18n = {
    locale: string;
    dir: TextDirection;
    t: TranslateFn;
    tn: TranslateNodesFn;
};

export function createI18n(
    locale: string,
    dir: TextDirection,
    translations: Translations,
): I18n {
    const t: TranslateFn = (key, replacements) =>
        translate(translations, locale, key, replacements);

    const tn: TranslateNodesFn = (key, nodes, replacements) =>
        interpolate(t(key, replacements), nodes).map((part, index) =>
            createElement(Fragment, { key: index }, part),
        );

    return { locale, dir, t, tn };
}

/**
 * Filled by I18nProvider from the shared Inertia props. Outside it (component
 * tests, previews) keys render as their English source text.
 */
export const I18nContext = createContext<I18n>(createI18n('en', 'ltr', {}));

/**
 *   const { t, tn } = useTranslation();
 *   t('Hello :name', { name });
 *   t(':count stamps left', { count }); // plural via Laravel's `a|b` syntax
 *   tn('Or, return to :link', { link: <TextLink href={login()}>{t('log in')}</TextLink> });
 */
export function useTranslation(): I18n {
    return useContext(I18nContext);
}

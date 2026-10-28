import { router, usePage } from '@inertiajs/react';
import type { HTMLAttributes } from 'react';
import { update } from '@/actions/App/Http/Controllers/LocaleController';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export default function LanguageSwitcher({
    className = '',
    size = 'default',
    ...props
}: HTMLAttributes<HTMLDivElement> & {
    /** sm: the compact segments of a header that shares its row (onboarding, BO Screen.dc.html .segs). */
    size?: 'default' | 'sm';
}) {
    const { locales } = usePage().props;
    const { t, locale } = useTranslation();

    const switchTo = (code: string) => {
        if (code !== locale) {
            router.put(
                update.url(),
                { locale: code },
                { preserveScroll: true },
            );
        }
    };

    return (
        <div
            role="group"
            aria-label={t('Language')}
            className={cn(
                size === 'sm'
                    ? 'inline-flex gap-0.5 rounded-sm bg-muted p-0.5'
                    : 'inline-flex gap-1 rounded-lg bg-muted p-1',
                className,
            )}
            {...props}
        >
            {locales.map(({ code, name }) => (
                <button
                    key={code}
                    type="button"
                    lang={code}
                    aria-pressed={locale === code}
                    onClick={() => switchTo(code)}
                    className={cn(
                        size === 'sm'
                            ? 'h-7 rounded-nav px-2 text-xs transition-colors'
                            : 'rounded-md px-3.5 py-1.5 text-sm transition-colors',
                        locale === code
                            ? 'bg-card text-foreground shadow-xs'
                            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                    )}
                >
                    {name}
                </button>
            ))}
        </div>
    );
}

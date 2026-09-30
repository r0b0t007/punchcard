import { router, usePage } from '@inertiajs/react';
import type { HTMLAttributes } from 'react';
import { update } from '@/actions/App/Http/Controllers/LocaleController';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export default function LanguageSwitcher({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
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
                'inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800',
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
                        'rounded-md px-3.5 py-1.5 text-sm transition-colors',
                        locale === code
                            ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                            : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                    )}
                >
                    {name}
                </button>
            ))}
        </div>
    );
}

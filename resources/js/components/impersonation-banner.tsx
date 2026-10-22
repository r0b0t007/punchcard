import { router, usePage } from '@inertiajs/react';
import { ArrowLeft, Eye } from 'lucide-react';
import { useLayoutEffect } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import { stop } from '@/routes/impersonation';

/**
 * The platform admin signed in as an owner (CHW-144, design 8a in Punchcard
 * Impersonation Review): an espresso bar above every page, whom and where,
 * read-only until when, and Back to admin. Marks <html data-impersonating>
 * so the fixed desktop sidebar starts below it (app.css).
 */
export default function ImpersonationBanner() {
    const { impersonating, locale } = usePage().props;
    const { t } = useTranslation();

    useLayoutEffect(() => {
        if (!impersonating) {
            return;
        }

        const root = document.documentElement;
        root.dataset.impersonating = '';

        return () => {
            delete root.dataset.impersonating;
        };
    }, [impersonating]);

    if (!impersonating) {
        return null;
    }

    const endsAt = new Intl.DateTimeFormat(locale, {
        hour: '2-digit',
        minute: '2-digit',
        // 24-hour, as the app's other times (LocalMoment).
        hourCycle: 'h23',
    }).format(new Date(impersonating.endsAt));

    return (
        <div
            role="status"
            className="sticky top-0 z-50 flex flex-wrap items-center gap-x-3.5 gap-y-2.5 bg-primary px-5 py-2.5 text-primary-foreground md:h-16 md:flex-nowrap md:py-0"
            data-test="impersonation-banner"
        >
            <Eye className="size-5 shrink-0" aria-hidden />
            <div className="flex min-w-0 flex-1 basis-56 flex-col gap-0.5">
                <span className="text-sm font-semibold">
                    {t("You're signed in as :name", {
                        name: impersonating.owner,
                    })}
                </span>
                <span className="text-xs">
                    {t(':business · Read-only · Ends at :time', {
                        business: impersonating.business,
                        time: endsAt,
                    })}
                </span>
            </div>
            <button
                type="button"
                onClick={() => router.post(stop.url())}
                className="inline-flex h-11 shrink-0 items-center gap-2 rounded-button border border-primary-foreground px-4 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-primary focus-visible:outline-none"
                data-test="impersonation-back"
            >
                <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden />
                {t('Back to admin')}
            </button>
        </div>
    );
}

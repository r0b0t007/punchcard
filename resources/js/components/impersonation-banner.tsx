import { router, usePage } from '@inertiajs/react';
import { ArrowLeft, Eye } from 'lucide-react';
import { useLayoutEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { localDate } from '@/lib/local-moment';
import { stop } from '@/routes/impersonation';

/**
 * The platform admin signed in as an owner (CHW-144, design 8a in Punchcard
 * Impersonation Review): an espresso bar above every page, whom and where,
 * read-only until when, and Back to admin. Marks <html data-impersonating>
 * so the fixed desktop sidebar starts below it (app.css). Never printed (the
 * QR stand).
 */
export default function ImpersonationBanner() {
    const { impersonating, locale } = usePage().props;
    const { t } = useTranslation();
    const [leaving, setLeaving] = useState(false);
    const shown = Boolean(impersonating);

    useLayoutEffect(() => {
        if (!shown) {
            return;
        }

        const root = document.documentElement;
        root.dataset.impersonating = '';

        return () => {
            delete root.dataset.impersonating;
        };
    }, [shown]);

    if (!impersonating) {
        return null;
    }

    // 24-hour with Latin digits, as the app's other times.
    const endsAt = localDate(locale, impersonating.endsAt, {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });

    return (
        <aside
            aria-label={t('Signed in as the owner')}
            className="sticky top-0 z-50 flex flex-wrap items-center gap-x-3.5 gap-y-2.5 bg-primary px-5 py-2.5 text-primary-foreground md:h-16 md:flex-nowrap md:py-0 print:hidden"
            data-test="impersonation-banner"
        >
            <Eye className="size-5 shrink-0" aria-hidden />
            <div className="flex min-w-0 flex-1 basis-56 flex-col gap-0.5">
                <span className="text-sm font-semibold md:truncate">
                    {t("You're signed in as :name", {
                        name: impersonating.owner,
                    })}
                </span>
                <span className="text-xs md:truncate">
                    {t(':business · Read-only · Ends at :time', {
                        business: impersonating.business,
                        time: endsAt,
                    })}
                </span>
            </div>
            <Button
                variant="on-primary"
                disabled={leaving}
                onClick={() =>
                    router.post(
                        stop.url(),
                        {},
                        {
                            onStart: () => setLeaving(true),
                            onFinish: () => setLeaving(false),
                        },
                    )
                }
                data-test="impersonation-back"
            >
                <ArrowLeft className="rtl:rotate-180" aria-hidden />
                {t('Back to admin')}
            </Button>
        </aside>
    );
}

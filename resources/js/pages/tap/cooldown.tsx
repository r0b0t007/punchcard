import { Head, Link } from '@inertiajs/react';
import TapScreen, { TapLoyaltyCard } from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { home } from '@/routes';

/** When the next stamp is possible, in the location's time (DescribeTap). */
type NextStamp = {
    /** now: the cooldown has passed since this tap. */
    day: 'now' | 'today' | 'tomorrow' | 'later';
    /** Y-m-d */
    date: string;
    /** H:i */
    time: string;
};

/** Stamped too recently on this card: information, not an error (CHW-25). */
export default function TapCooldown({
    card,
    nextStamp,
    stampedMinutesAgo,
}: {
    card: TapCard | null;
    nextStamp: NextStamp | null;
    stampedMinutesAgo: number | null;
}) {
    const { t, locale } = useTranslation();
    const collected = card?.stampsCollected ?? 0;

    // A cooldown can last a day or more: minutes, then hours, then days.
    const heading = (() => {
        if (stampedMinutesAgo === null) {
            return t('Already stamped');
        }

        if (stampedMinutesAgo === 0) {
            return t('Already stamped just now.');
        }

        if (stampedMinutesAgo < 60) {
            return t(
                'Already stamped :count minute ago.|Already stamped :count minutes ago.',
                { count: stampedMinutesAgo },
            );
        }

        if (stampedMinutesAgo < 1440) {
            return t(
                'Already stamped :count hour ago.|Already stamped :count hours ago.',
                { count: Math.floor(stampedMinutesAgo / 60) },
            );
        }

        return t(
            'Already stamped :count day ago.|Already stamped :count days ago.',
            { count: Math.floor(stampedMinutesAgo / 1440) },
        );
    })();

    const label = (next: NextStamp): string => {
        switch (next.day) {
            case 'later':
                return t('Next stamp available on :date at', {
                    // A calendar date: format it in UTC so no timezone shifts the day.
                    date: new Intl.DateTimeFormat(locale, {
                        weekday: 'long',
                        day: 'numeric',
                        month: 'long',
                        timeZone: 'UTC',
                    }).format(new Date(`${next.date}T00:00:00Z`)),
                });
            case 'tomorrow':
                return t('Next stamp available tomorrow at');
            default:
                return t('Next stamp available at');
        }
    };

    return (
        <>
            <Head title={t('Already stamped')} />
            <TapScreen
                actions={
                    <Button asChild size="lg">
                        <Link href={home()}>{t('Done')}</Link>
                    </Button>
                }
            >
                {card ? <TapLoyaltyCard card={card} /> : null}
                <div className="flex flex-col gap-4">
                    <h1 className="font-display text-4xl leading-tight font-bold text-balance">
                        {heading}
                    </h1>
                    {nextStamp && nextStamp.day !== 'now' ? (
                        <div className="flex flex-col rounded-card border border-border bg-card px-5 py-4">
                            <p className="text-muted-foreground">
                                {label(nextStamp)}
                            </p>
                            <p className="font-display text-4xl font-bold tabular-nums">
                                {nextStamp.time}
                            </p>
                        </div>
                    ) : null}
                    <p className="text-lg text-muted-foreground">
                        {nextStamp?.day === 'now'
                            ? t(
                                  'You can collect your next stamp now. Touch the stamper again.',
                              )
                            : collected > 0
                              ? t(
                                    'Your stamp is safe. See you next visit.|Your :count stamps are safe. See you next visit.',
                                    { count: collected },
                                )
                              : null}
                    </p>
                </div>
            </TapScreen>
        </>
    );
}

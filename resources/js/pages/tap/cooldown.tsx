import { Head } from '@inertiajs/react';
import TapScreen from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import { useTranslation } from '@/hooks/use-translation';

/** When the next stamp is possible, in the location's time (DescribeTap). */
type NextStamp = {
    /** now: the cooldown has passed since this tap. */
    day: 'now' | 'today' | 'tomorrow' | 'later';
    /** Y-m-d */
    date: string;
    /** H:i */
    time: string;
};

/** Stamped too recently on this card: friendly, not an error (CHW-25). */
export default function TapCooldown({
    card,
    nextStamp,
}: {
    card: TapCard | null;
    nextStamp: NextStamp | null;
}) {
    const { t, locale } = useTranslation();

    const message = (next: NextStamp): string => {
        switch (next.day) {
            case 'now':
                return t(
                    'You can collect your next stamp now. Touch the stamper again.',
                );
            case 'today':
                return t('Your next stamp is available at :time.', {
                    time: next.time,
                });
            case 'tomorrow':
                return t('Your next stamp is available tomorrow at :time.', {
                    time: next.time,
                });
            case 'later':
                return t('Your next stamp is available on :date at :time.', {
                    // A calendar date: format it in UTC so no timezone shifts the day.
                    date: new Intl.DateTimeFormat(locale, {
                        weekday: 'long',
                        day: 'numeric',
                        month: 'long',
                        timeZone: 'UTC',
                    }).format(new Date(`${next.date}T00:00:00Z`)),
                    time: next.time,
                });
        }
    };

    return (
        <>
            <Head title={t('Already stamped')} />
            <TapScreen title={t('Already stamped')} card={card}>
                {nextStamp ? (
                    <p className="text-muted-foreground">
                        {message(nextStamp)}
                    </p>
                ) : null}
            </TapScreen>
        </>
    );
}

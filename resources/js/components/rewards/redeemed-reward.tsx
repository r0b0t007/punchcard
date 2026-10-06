import { Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import { momentLabel } from '@/lib/local-moment';
import type { LocalMoment } from '@/lib/local-moment';

/**
 * A redeemed reward, for staff to glance at (CHW-26, Claude Design
 * "Punchcard Redemption" 06). Live for liveSeconds (the server's count, from
 * the redemption): a big check and the phone's clock ticking, so staff can
 * tell it from a screenshot. Then, even on a screen left open, it only says it
 * was already redeemed, with no check to hand anything over on.
 */
export default function RedeemedReward({
    rewardText,
    place,
    redeemedAt,
    liveSeconds,
}: {
    rewardText: string;
    place: string;
    redeemedAt: LocalMoment;
    liveSeconds: number;
}) {
    const { t, locale } = useTranslation();
    const [liveLeft, setLiveLeft] = useState(liveSeconds);
    const live = liveLeft > 0;

    useEffect(() => {
        if (liveLeft <= 0) {
            return;
        }

        const timer = window.setTimeout(() => setLiveLeft(liveLeft - 1), 1000);

        return () => window.clearTimeout(timer);
    }, [liveLeft]);

    return (
        <>
            <p
                className={`flex items-center gap-2 self-start rounded-full bg-accent px-4 py-2 font-medium ${live ? 'text-success' : 'text-muted-foreground'}`}
            >
                <span
                    aria-hidden="true"
                    className={`size-2 rounded-full ${live ? 'bg-success' : 'bg-muted-foreground'}`}
                />
                {momentLabel(locale, redeemedAt, {
                    today: (time) => t('Redeemed today at :time', { time }),
                    other: (date, time) =>
                        t('Redeemed on :date at :time', { date, time }),
                })}
            </p>
            <div className="flex flex-col items-center gap-5 rounded-card border border-border bg-card px-6 pt-8 pb-7 text-center">
                {live ? (
                    <span
                        aria-hidden="true"
                        className="grid size-24 place-items-center rounded-full bg-success text-background"
                    >
                        <Check className="size-12" strokeWidth={2.6} />
                    </span>
                ) : null}
                <div className="flex flex-col gap-2">
                    {live ? null : (
                        <p className="text-lg text-muted-foreground">
                            {t('Already redeemed')}
                        </p>
                    )}
                    <h1 className="font-display text-5xl leading-none font-extrabold text-balance">
                        {rewardText}
                    </h1>
                    <p className="text-lg text-muted-foreground">{place}</p>
                </div>
                {live ? (
                    <>
                        <div className="w-full border-t border-dashed border-border" />
                        <LiveClock />
                    </>
                ) : null}
            </div>
        </>
    );
}

/** The phone's own time, to the second: a screenshot stops, this doesn't. */
function LiveClock() {
    const { t } = useTranslation();
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    const time = new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    }).format(now);

    return (
        <div className="flex flex-col items-center gap-1">
            <p className="flex items-center gap-2.5">
                <span
                    aria-hidden="true"
                    className="size-2.5 rounded-full bg-success motion-safe:animate-pulse"
                />
                <time
                    dir="ltr"
                    dateTime={now.toISOString()}
                    className="font-display text-4xl leading-tight font-bold tabular-nums"
                >
                    {time}
                </time>
            </p>
            <p className="text-muted-foreground">
                {t('Show this screen to staff')}
            </p>
        </div>
    );
}

import { Head } from '@inertiajs/react';
import TapScreen, { TapLoyaltyCard } from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import { useTranslation } from '@/hooks/use-translation';

/** When the stamp was given, in the location's time (DescribeTap). */
type Moment = {
    day: 'today' | 'other';
    /** Y-m-d */
    date: string;
    /** H:i */
    time: string;
};

/** C2: the stamp landed; a reward it unlocked is announced in place (CHW-25). */
export default function TapStamped({
    card,
    given,
    rewards,
    stampedAt,
    stampsBefore,
    fresh,
}: {
    card: TapCard | null;
    given: number;
    rewards: string[];
    stampedAt: Moment | null;
    stampsBefore: number;
    fresh: boolean;
}) {
    const { t, locale } = useTranslation();
    const added = t(':count stamp added|:count stamps added', {
        count: given,
    });
    const remaining = card
        ? Math.max(card.stampsRequired - card.stampsCollected, 0)
        : 0;

    return (
        <>
            <Head title={added} />
            <TapScreen>
                {stampedAt ? (
                    <p className="flex items-center gap-2 self-start rounded-full bg-accent px-4 py-2 font-medium text-success">
                        <span
                            aria-hidden="true"
                            className="size-2 rounded-full bg-success"
                        />
                        {stampedAt.day === 'today'
                            ? t('Stamped at :time', { time: stampedAt.time })
                            : t('Stamped on :date at :time', {
                                  // A calendar date: format it in UTC so no timezone shifts the day.
                                  date: new Intl.DateTimeFormat(locale, {
                                      weekday: 'long',
                                      day: 'numeric',
                                      month: 'long',
                                      timeZone: 'UTC',
                                  }).format(
                                      new Date(`${stampedAt.date}T00:00:00Z`),
                                  ),
                                  time: stampedAt.time,
                              })}
                    </p>
                ) : null}
                {card ? (
                    // The stamp lands on the first look only, not on a reload or a later visit.
                    <TapLoyaltyCard
                        card={card}
                        landingFrom={fresh ? stampsBefore : undefined}
                    />
                ) : null}
                {rewards.length > 0 ? (
                    <div className="flex flex-col gap-3">
                        <p className="self-start rounded-full bg-stamp px-4 py-2 font-semibold text-stamp-foreground">
                            {t('Reward unlocked')}
                        </p>
                        <h1 className="flex flex-col gap-2 font-display text-5xl leading-none font-extrabold">
                            {rewards.map((reward, index) => (
                                // Two rewards can share a text (a cyclic card completed twice).
                                <span key={index}>{reward}</span>
                            ))}
                        </h1>
                        <p className="text-lg text-muted-foreground">
                            {t('Have it today or keep it for your next visit.')}
                        </p>
                    </div>
                ) : card ? (
                    <div className="flex flex-col gap-2">
                        {/* A progressive card past its last tier keeps counting: no "of", nothing left to go. */}
                        <h1 className="font-display text-5xl leading-none font-bold tabular-nums">
                            {remaining > 0
                                ? t(':collected of :required', {
                                      collected: card.stampsCollected,
                                      required: card.stampsRequired,
                                  })
                                : t(':count stamp|:count stamps', {
                                      count: card.stampsCollected,
                                  })}
                        </h1>
                        {remaining > 0 ? (
                            <p className="text-xl">
                                {t(
                                    ':count more stamp to your reward|:count more stamps to your reward',
                                    { count: remaining },
                                )}
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <h1 className="font-display text-5xl leading-none font-bold">
                        {added}
                    </h1>
                )}
            </TapScreen>
        </>
    );
}

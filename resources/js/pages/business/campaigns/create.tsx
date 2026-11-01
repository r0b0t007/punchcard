import { Head, useForm } from '@inertiajs/react';
import { Hourglass, Smartphone } from 'lucide-react';
import { useRef, useState } from 'react';
import LockPreview from '@/components/campaigns/lock-preview';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslateFn } from '@/hooks/use-translation';
import {
    addDays,
    characters,
    clip,
    newIdempotencyKey,
    nextHour,
    notificationTitle,
} from '@/lib/campaigns';
import { calendarDate, momentLabel } from '@/lib/local-moment';
import type { LocalMoment } from '@/lib/local-moment';
import { cn, typedDir } from '@/lib/utils';
import { create, index, store } from '@/routes/campaigns';

type Segment = 'all' | 'quiet_14d' | 'top_10';
type Audience = { followers: number; reach: number };
type When = 'now' | 'later';

/** What tapping it opens: a card's id, or MY_CARDS. */
const MY_CARDS = 'cards';

/**
 * The composer (CHW-42, design 12a): who gets it (each audience's size,
 * and who among them has a phone with notifications on), the title (beside
 * the business's name) and message with their counters, what tapping it
 * opens, and the notification as a customer's lock screen shows it: beside
 * the form on a computer, below it on a phone, where Send stays pinned.
 * Send confirms once; nothing to send to, or the week's campaigns sent,
 * and it waits. The owner's, never staff's.
 */
export default function CreateCampaign({
    business,
    segments,
    cards,
    left,
    max,
    nextAt,
    titleRoom,
    bodyMax,
    site,
    now,
    scheduleDays,
}: {
    business: string;
    segments: Record<Segment, Audience>;
    cards: { id: number; name: string }[];
    left: number;
    max: number;
    nextAt: LocalMoment | null;
    titleRoom: number;
    bodyMax: number;
    /** The site whose time a later campaign is set in, and the moment there. */
    site: string | null;
    now: LocalMoment;
    scheduleDays: number;
}) {
    const { t, locale } = useTranslation();
    // One key per composer, made once, never rendered: a double tap on Send, or a retried post, is one campaign.
    const [idempotencyKey] = useState(newIdempotencyKey);
    const suggested = nextHour(now.date, now.time);
    const form = useForm({
        idempotency_key: idempotencyKey,
        segment: 'all' as Segment,
        title: '',
        body: '',
        card: cards.length > 0 ? String(cards[0].id) : MY_CARDS,
        when: 'now' as When,
        send_on: suggested.date,
        send_time: suggested.time,
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const [confirming, setConfirming] = useState(false);

    const chosen = segments[data.segment];
    const full = left === 0;
    const later = data.when === 'later';
    // Later, the week's limit is the scheduled moment's: the server counts it then.
    const canSend = chosen.reach > 0 && (later || !full);
    const order = Object.keys(segments) as Segment[];
    const audienceCards = useRef<Partial<Record<Segment, HTMLButtonElement>>>(
        {},
    );

    // A radio group: the arrow keys choose the next or previous audience (mirrored right to left), one Tab stop.
    const moveAudience = (event: React.KeyboardEvent<HTMLDivElement>) => {
        const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';
        const steps: Record<string, number> = {
            ArrowDown: 1,
            ArrowUp: -1,
            ArrowRight: rtl ? -1 : 1,
            ArrowLeft: rtl ? 1 : -1,
        };
        const step = steps[event.key];

        if (step === undefined) {
            return;
        }

        event.preventDefault();
        const next =
            order[
                (order.indexOf(data.segment) + step + order.length) %
                    order.length
            ];
        setData('segment', next);
        audienceCards.current[next]?.focus();
    };

    const send = () => {
        form.transform((typed) => ({
            idempotency_key: typed.idempotency_key,
            segment: typed.segment,
            title: typed.title,
            body: typed.body,
            card_id: typed.card === MY_CARDS ? null : Number(typed.card),
            when: typed.when,
            ...(typed.when === 'later'
                ? { send_on: typed.send_on, send_time: typed.send_time }
                : {}),
        }));
        form.submit(store(), {
            preserveScroll: true,
            onFinish: () => setConfirming(false),
        });
    };

    return (
        <>
            <Head title={t('New campaign')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4">
                <Heading
                    title={t('New campaign')}
                    description={t(
                        'Send your followers a notification: an offer, a slow afternoon, something new.',
                    )}
                />

                {/* One notice: the server's refusal (its own moment's, for a later one), or the week's campaigns sent. */}
                {errors.campaign ? (
                    <Notice>{errors.campaign}</Notice>
                ) : full && nextAt ? (
                    <Notice>
                        {momentLabel(locale, nextAt, {
                            today: (time) =>
                                t(
                                    'You have sent :max campaigns in 7 days. The next one can go today at :time.',
                                    { max, time },
                                ),
                            other: (date, time) =>
                                t(
                                    'You have sent :max campaigns in 7 days. The next one can go from :date.',
                                    {
                                        max,
                                        date: t(':date, :time', { date, time }),
                                    },
                                ),
                        })}
                    </Notice>
                ) : null}

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        setConfirming(true);
                    }}
                    className="flex flex-col gap-6 lg:grid lg:grid-cols-3 lg:items-start lg:gap-x-8"
                >
                    <div className="flex min-w-0 flex-col gap-6 lg:col-span-2">
                        <fieldset className="grid gap-2.5">
                            <legend className="mb-2.5 text-sm font-medium">
                                {t('Who gets it')}
                            </legend>
                            <div
                                role="radiogroup"
                                aria-label={t('Who gets it')}
                                className="grid gap-2.5 md:grid-cols-3"
                                onKeyDown={moveAudience}
                            >
                                {(Object.keys(segments) as Segment[]).map(
                                    (segment) => (
                                        <AudienceCard
                                            key={segment}
                                            segment={segment}
                                            business={business}
                                            audience={segments[segment]}
                                            chosen={data.segment === segment}
                                            onChoose={() =>
                                                setData('segment', segment)
                                            }
                                            buttonRef={(button) => {
                                                audienceCards.current[segment] =
                                                    button ?? undefined;
                                            }}
                                        />
                                    ),
                                )}
                            </div>
                            <p
                                className="flex items-center gap-2 text-sm text-muted-foreground"
                                data-test="campaign-reach"
                            >
                                <Smartphone
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                {chosen.reach > 0
                                    ? t(
                                          ':reach of :followers have notifications on a phone.',
                                          {
                                              reach: chosen.reach,
                                              followers: chosen.followers,
                                          },
                                      )
                                    : t(
                                          'None of them has notifications on a phone yet.',
                                      )}
                            </p>
                            <InputError message={errors.segment} />
                        </fieldset>

                        <div className="grid gap-2">
                            <div className="flex items-baseline justify-between gap-2">
                                <Label htmlFor="title">{t('Title')}</Label>
                                <Counter value={data.title} max={titleRoom} />
                            </div>
                            <Input
                                id="title"
                                dir={typedDir(data.title)}
                                required
                                placeholder={t('Slow Tuesday?')}
                                value={data.title}
                                onChange={(event) =>
                                    setData(
                                        'title',
                                        clip(event.target.value, titleRoom),
                                    )
                                }
                            />
                            <InputError message={errors.title} />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-baseline justify-between gap-2">
                                <Label htmlFor="body">{t('Message')}</Label>
                                <Counter value={data.body} max={bodyMax} />
                            </div>
                            <Textarea
                                id="body"
                                dir={typedDir(data.body)}
                                required
                                rows={3}
                                placeholder={t(
                                    'Two stamps for every coffee until 4 pm today.',
                                )}
                                value={data.body}
                                onChange={(event) =>
                                    setData(
                                        'body',
                                        clip(event.target.value, bodyMax),
                                    )
                                }
                            />
                            <InputError message={errors.body} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="card">
                                {t('Tapping it opens')}
                            </Label>
                            <Select
                                value={data.card}
                                onValueChange={(card) => setData('card', card)}
                            >
                                <SelectTrigger id="card" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {cards.map((card) => (
                                        <SelectItem
                                            key={card.id}
                                            value={String(card.id)}
                                        >
                                            {card.name}
                                        </SelectItem>
                                    ))}
                                    <SelectItem value={MY_CARDS}>
                                        {t('My cards')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.card_id} />
                        </div>

                        <div className="grid gap-2.5">
                            <Label id="when-label">{t('When')}</Label>
                            <ToggleGroup
                                type="single"
                                variant="segmented"
                                aria-labelledby="when-label"
                                className="self-start justify-self-start"
                                value={data.when}
                                onValueChange={(when) =>
                                    when && setData('when', when as When)
                                }
                            >
                                <ToggleGroupItem
                                    value="now"
                                    data-test="when-now"
                                >
                                    {t('Now')}
                                </ToggleGroupItem>
                                <ToggleGroupItem
                                    value="later"
                                    data-test="when-later"
                                >
                                    {t('Later')}
                                </ToggleGroupItem>
                            </ToggleGroup>
                            {later ? (
                                <>
                                    <div className="flex gap-2.5">
                                        <Input
                                            type="date"
                                            aria-label={t('Day')}
                                            required
                                            min={now.date}
                                            max={addDays(
                                                now.date,
                                                scheduleDays,
                                            )}
                                            value={data.send_on}
                                            onChange={(event) =>
                                                setData(
                                                    'send_on',
                                                    event.target.value,
                                                )
                                            }
                                            data-test="send-on"
                                        />
                                        <Input
                                            type="time"
                                            aria-label={t('Time')}
                                            required
                                            className="max-w-36"
                                            value={data.send_time}
                                            onChange={(event) =>
                                                setData(
                                                    'send_time',
                                                    event.target.value,
                                                )
                                            }
                                            data-test="send-time"
                                        />
                                    </div>
                                    {site ? (
                                        <p className="text-sm text-muted-foreground">
                                            {t('In :site’s time.', { site })}
                                        </p>
                                    ) : null}
                                    <InputError
                                        message={
                                            errors.send_on ?? errors.send_time
                                        }
                                    />
                                </>
                            ) : null}
                        </div>
                    </div>

                    <aside className="flex flex-col gap-2.5 lg:sticky lg:top-4 lg:col-start-3 lg:row-span-2 lg:row-start-1">
                        <p className="text-sm font-medium">
                            {t('On a customer’s lock screen')}
                        </p>
                        <LockPreview
                            title={notificationTitle(
                                business,
                                data.title || t('Your title'),
                            )}
                            body={data.body || t('Your message')}
                        />
                        <p className="text-sm text-muted-foreground">
                            {t('Phones draw it their own way; this is close.')}
                        </p>
                    </aside>

                    {/* Pinned to the bottom on a phone, under the form on a computer. */}
                    <div className="sticky bottom-0 -mx-4 flex flex-col gap-2.5 border-t bg-background px-4 py-3 lg:static lg:col-span-2 lg:mx-0 lg:border-0 lg:p-0">
                        <Button
                            type="submit"
                            size="lg"
                            disabled={!canSend || processing}
                            data-test="campaign-send"
                        >
                            {later
                                ? t(
                                      'Schedule for :count person|Schedule for :count people',
                                      { count: chosen.reach },
                                  )
                                : t(
                                      'Send to :count person|Send to :count people',
                                      { count: chosen.reach },
                                  )}
                        </Button>
                        <p className="text-center text-sm text-muted-foreground">
                            {t(':count of :max campaigns left this week.', {
                                count: left,
                                max,
                            })}
                        </p>
                    </div>
                </form>
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogTitle>
                        {later
                            ? t(
                                  'Schedule for :count person?|Schedule for :count people?',
                                  { count: chosen.reach },
                              )
                            : t(
                                  'Send to :count person now?|Send to :count people now?',
                                  { count: chosen.reach },
                              )}
                    </DialogTitle>
                    <DialogDescription>
                        {later
                            ? t(
                                  ':audience, at :business, on :date at :time. You can cancel it until then.',
                                  {
                                      audience: audienceName(t, data.segment),
                                      business,
                                      date: calendarDate(locale, data.send_on, {
                                          weekday: 'long',
                                          day: 'numeric',
                                          month: 'long',
                                      }),
                                      time: data.send_time,
                                  },
                              )
                            : t(
                                  ':audience, at :business. A notification can’t be taken back once sent.',
                                  {
                                      audience: audienceName(t, data.segment),
                                      business,
                                  },
                              )}
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="secondary">{t('Cancel')}</Button>
                        </DialogClose>
                        <Button
                            onClick={send}
                            disabled={processing}
                            data-test="campaign-confirm"
                        >
                            {processing ? <Spinner /> : null}
                            {later ? t('Schedule') : t('Send now')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function AudienceCard({
    segment,
    business,
    audience,
    chosen,
    onChoose,
    buttonRef,
}: {
    segment: Segment;
    business: string;
    audience: Audience;
    chosen: boolean;
    onChoose: () => void;
    buttonRef: (button: HTMLButtonElement | null) => void;
}) {
    const { t } = useTranslation();
    const description = {
        all: t('Everyone following :business for offers.', { business }),
        quiet_14d: t('Followers with no stamp here for 14 days or more.'),
        top_10: t('Your 10 followers with the most stamps here in 90 days.'),
    }[segment];

    return (
        <button
            type="button"
            role="radio"
            aria-checked={chosen}
            tabIndex={chosen ? 0 : -1}
            ref={buttonRef}
            onClick={onChoose}
            className={cn(
                'relative flex flex-col gap-1 rounded-card border bg-card p-3.5 text-start outline-none focus-visible:ring-3 focus-visible:ring-ring',
                chosen && 'border-primary ring-1 ring-primary',
            )}
            data-test={`audience-${segment}`}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'absolute end-3.5 top-3.5 size-4.5 rounded-full border border-input',
                    chosen && 'border-5 border-primary',
                )}
            />
            {/* As wide as the number, at the start: clear of the radio at the end, in either direction. */}
            <span className="self-start font-display text-title leading-none font-bold">
                {audience.followers}
            </span>
            <span className="text-md font-semibold">
                {audienceName(t, segment)}
            </span>
            <span className="text-sm text-muted-foreground">{description}</span>
        </button>
    );
}

function audienceName(t: TranslateFn, segment: Segment): string {
    return {
        all: t('All followers'),
        quiet_14d: t('Not seen in 14 days'),
        top_10: t('Top 10'),
    }[segment];
}

/** "13 / 35", always left to right. */
function Counter({ value, max }: { value: string; max: number }) {
    return (
        <span className="text-xs text-muted-foreground tabular-nums" dir="ltr">
            {characters(value)} / {max}
        </span>
    );
}

function Notice({ children }: { children: React.ReactNode }) {
    return (
        <Alert variant="warning" data-test="campaign-notice">
            <Hourglass aria-hidden="true" />
            <AlertDescription>{children}</AlertDescription>
        </Alert>
    );
}

CreateCampaign.layout = {
    breadcrumbs: [
        { title: 'Campaigns', href: index() },
        { title: 'New campaign', href: create() },
    ],
};

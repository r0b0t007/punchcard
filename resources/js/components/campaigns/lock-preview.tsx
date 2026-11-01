import { useEffect, useState } from 'react';
import { StampDot } from '@/components/wordmark';
import { useTranslation } from '@/hooks/use-translation';
import { localDate } from '@/lib/local-moment';

/**
 * A campaign's notification on a customer's lock screen (CHW-42, designs
 * 12a and 12d): the punchcard app tile, the title (the business's name,
 * then the owner's) and the message, as the owner types them. Phones draw
 * it their own way: this is close. The day is the phone's, read once
 * mounted (nothing reads the clock while the page renders on the server).
 */
export default function LockPreview({
    title,
    body,
}: {
    title: string;
    body: string;
}) {
    const { t, locale } = useTranslation();
    const [day, setDay] = useState('');

    useEffect(() => {
        setDay(
            localDate(locale, new Date().toISOString(), {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
            }),
        );
    }, [locale]);

    return (
        <div
            className="flex flex-col items-stretch gap-2.5 overflow-hidden rounded-panel bg-primary px-3 pt-11 pb-7 text-primary-foreground dark:bg-muted dark:text-foreground"
            data-test="lock-preview"
        >
            <p
                className="text-center font-display text-6xl leading-none font-semibold"
                dir="ltr"
            >
                9:41
            </p>
            <p className="mb-16 min-h-5 text-center text-sm font-medium opacity-85">
                {day}
            </p>
            <div className="flex items-start gap-3 rounded-card bg-card/90 px-3.5 py-3 text-start text-foreground">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-primary dark:bg-foreground">
                    <StampDot halo={false} className="size-3" />
                </span>
                <span className="flex min-w-0 flex-1 flex-col gap-px">
                    <span className="flex justify-between gap-2 text-xs opacity-70">
                        <span dir="ltr">punchcard</span>
                        <span>{t('now')}</span>
                    </span>
                    <b className="text-md font-semibold break-words">
                        <bdi>{title}</bdi>
                    </b>
                    <span className="text-sm leading-snug break-words">
                        <bdi>{body}</bdi>
                    </span>
                </span>
            </div>
        </div>
    );
}

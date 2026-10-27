import type { ReactNode } from 'react';
import { useSecond } from '@/lib/seconds';
import { cn } from '@/lib/utils';

/**
 * The phone's own time, to the second, after a pulsing green dot (still
 * under reduced motion): a screenshot stops, this doesn't. Staff glance at
 * it on a redeemed reward (CHW-26) and under My QR (CHW-217). Always left
 * to right, in 24-hour digits; the size comes from the caller (className),
 * an optional caption sits under it. Until the phone's clock is read (a page
 * rendered on the server) it keeps its place, blank. The formatter is made
 * on each render: made once, it would keep the timezone the phone had then.
 */
export default function LiveClock({
    className,
    caption,
}: {
    className?: string;
    caption?: ReactNode;
}) {
    const now = useSecond();

    return (
        <div className="flex flex-col items-center gap-1">
            <p dir="ltr" className="flex items-center gap-3">
                <span
                    aria-hidden="true"
                    className="size-2.5 rounded-full bg-success ring-4 ring-success/20 motion-safe:animate-pulse"
                />
                <time
                    dateTime={
                        now === null ? undefined : new Date(now).toISOString()
                    }
                    aria-hidden={now === null ? true : undefined}
                    className={cn(
                        'font-display font-bold tabular-nums',
                        now === null && 'invisible',
                        className,
                        // After the caller's size, which would otherwise set its own line height.
                        'leading-none',
                    )}
                >
                    {now === null
                        ? '00:00:00'
                        : new Intl.DateTimeFormat('en-GB', {
                              hour: '2-digit',
                              minute: '2-digit',
                              second: '2-digit',
                              hourCycle: 'h23',
                          }).format(now)}
                </time>
            </p>
            {caption ? (
                <p className="text-muted-foreground">{caption}</p>
            ) : null}
        </div>
    );
}

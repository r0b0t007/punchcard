import type { ReactNode } from 'react';
import { useSecond } from '@/lib/seconds';
import { cn } from '@/lib/utils';

const TIME = new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
});

/**
 * The phone's own time, to the second, after a pulsing green dot (still
 * under reduced motion): a screenshot stops, this doesn't. Staff glance at
 * it on a redeemed reward (CHW-26) and under My QR (CHW-217). Always left
 * to right, in 24-hour digits; the size comes from the caller (className),
 * an optional caption sits under it. Until the phone's clock is read (a page
 * rendered on the server) it keeps its place, blank.
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
                {now === null ? (
                    <span
                        aria-hidden="true"
                        className={cn(
                            'invisible font-display font-bold tabular-nums',
                            className,
                            'leading-none',
                        )}
                    >
                        00:00:00
                    </span>
                ) : (
                    <time
                        dateTime={new Date(now).toISOString()}
                        className={cn(
                            'font-display font-bold tabular-nums',
                            className,
                            // After the caller's size, which would otherwise set its own line height.
                            'leading-none',
                        )}
                    >
                        {TIME.format(now)}
                    </time>
                )}
            </p>
            {caption ? (
                <p className="text-muted-foreground">{caption}</p>
            ) : null}
        </div>
    );
}

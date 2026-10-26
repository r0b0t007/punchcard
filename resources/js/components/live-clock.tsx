import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * The phone's own time, to the second, after a pulsing green dot (still
 * under reduced motion): a screenshot stops, this doesn't. Staff glance at
 * it on a redeemed reward (CHW-26) and under My QR (CHW-217). Always left
 * to right, in 24-hour digits; the size comes from the caller (className).
 */
export default function LiveClock({ className }: { className?: string }) {
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
        <p dir="ltr" className="flex items-center gap-3">
            <span
                aria-hidden="true"
                className="size-2.5 rounded-full bg-success ring-4 ring-success/20 motion-safe:animate-pulse"
            />
            <time
                dateTime={now.toISOString()}
                className={cn(
                    'font-display leading-none font-bold tabular-nums',
                    className,
                )}
            >
                {time}
            </time>
        </p>
    );
}

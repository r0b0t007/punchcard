import { useEffect, useState } from 'react';
import { useTranslation } from '@/hooks/use-translation';

const RADIUS = 100;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

/**
 * The redeem window's countdown (C4, Claude Design "Punchcard Tap Flow" 04):
 * a ring that empties clockwise (counter-clockwise in Arabic) around the
 * seconds left. Starts from the server's count and calls onDone at zero.
 */
export default function CountdownRing({
    seconds,
    total,
    onDone,
}: {
    seconds: number;
    total: number;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const [left, setLeft] = useState(seconds);

    useEffect(() => setLeft(seconds), [seconds]);

    useEffect(() => {
        if (left <= 0) {
            onDone();

            return;
        }

        const timer = window.setTimeout(() => setLeft(left - 1), 1000);

        return () => window.clearTimeout(timer);
    }, [left, onDone]);

    return (
        <div
            role="timer"
            aria-live="off"
            aria-label={t(':count second left|:count seconds left', {
                count: left,
            })}
            className="relative size-55"
        >
            <svg
                aria-hidden="true"
                viewBox="0 0 220 220"
                className="absolute inset-0 size-full -rotate-90 rtl:-scale-y-100"
            >
                <circle
                    cx="110"
                    cy="110"
                    r={RADIUS}
                    fill="none"
                    strokeWidth="12"
                    className="stroke-border"
                />
                <circle
                    cx="110"
                    cy="110"
                    r={RADIUS}
                    fill="none"
                    strokeWidth="12"
                    strokeLinecap="round"
                    strokeDasharray={CIRCUMFERENCE}
                    strokeDashoffset={
                        CIRCUMFERENCE * (1 - Math.max(left, 0) / total)
                    }
                    className="stroke-stamp transition-all duration-1000 ease-linear motion-reduce:transition-none"
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="font-display text-8xl leading-none font-bold tabular-nums">
                    {Math.max(left, 0)}
                </span>
                <span className="text-muted-foreground">{t('seconds')}</span>
            </div>
        </div>
    );
}

import { cn } from '@/lib/utils';

/**
 * The saffron stamp dot of the punchcard mark, with its soft halo
 * (`Punchcard Sign In.dc.html`). Alone, it is the onboarding header's mark;
 * on the sidebar's tile it goes without the halo.
 */
export function StampDot({
    halo = true,
    className,
}: {
    halo?: boolean;
    className?: string;
}) {
    return (
        <span
            aria-hidden
            className={cn(
                'inline-block shrink-0 rounded-full bg-stamp',
                halo && 'ring-3 ring-stamp/30',
                className,
            )}
        />
    );
}

/**
 * The punchcard wordmark: the stamp dot and "punchcard" in Baloo Bhaijaan 2,
 * 800. A logo, so it stays Latin and left to right in every locale.
 */
export function Wordmark({ className }: { className?: string }) {
    return (
        <span
            dir="ltr"
            className={cn(
                'inline-flex items-center gap-2 font-display text-2xl leading-none font-extrabold',
                className,
            )}
        >
            <StampDot className="size-3.5" />
            punchcard
        </span>
    );
}

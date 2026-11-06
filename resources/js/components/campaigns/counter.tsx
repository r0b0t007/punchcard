import { characters } from '@/lib/campaigns';

/** A title's or message's characters as a phone counts them, against the most it may have (campaigns, automations). */
export function Counter({ value, max }: { value: string; max: number }) {
    return (
        <span className="text-xs text-muted-foreground tabular-nums" dir="ltr">
            {characters(value)} / {max}
        </span>
    );
}

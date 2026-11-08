import { MapPin } from 'lucide-react';

/** The businesses someone is a customer of, across a franchise's sites (CHW-365, design 23). */
export default function Sites({ names }: { names: string[] }) {
    return (
        <span
            className="inline-flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
            data-test="customer-sites"
        >
            <MapPin aria-hidden="true" className="size-3.5 shrink-0" />
            <span className="truncate">
                {names.map((name, i) => (
                    <span key={i}>
                        {i > 0 ? ' · ' : null}
                        <bdi>{name}</bdi>
                    </span>
                ))}
            </span>
        </span>
    );
}

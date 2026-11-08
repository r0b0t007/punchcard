import { Building2 } from 'lucide-react';

/**
 * The business an event or a note belongs to, for a franchise's head office
 * (CHW-365, design 23): each site's name, in its own direction.
 */
export default function BusinessTag({ name }: { name: string }) {
    return (
        <span
            className="mt-1 inline-flex w-fit items-center gap-1.5 rounded-full bg-muted px-2 py-0.5 text-xs font-semibold"
            data-test="business-tag"
        >
            <Building2 aria-hidden="true" className="size-3.5" />
            <bdi>{name}</bdi>
        </span>
    );
}

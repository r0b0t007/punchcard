import { usePage } from '@inertiajs/react';

import { StampDot } from '@/components/wordmark';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-sm bg-sidebar-primary">
                <StampDot className="size-3 ring-0" />
            </div>
            <div className="ms-1 grid flex-1 text-start">
                <span className="truncate font-display text-xl leading-tight font-bold">
                    {name}
                </span>
            </div>
        </>
    );
}

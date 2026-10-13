import { Download, Share, SquarePlus } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useTranslation } from '@/hooks/use-translation';
import {
    install,
    installOffer,
    isStandalone,
    snooze,
    snoozedUntil,
    useInstallPrompt,
} from '@/lib/install-prompt';

/** Long enough for the stamp to land first (TapLoyaltyCard). */
const OPENS_AFTER_MS = 1500;

/**
 * C13 (CHW-35): keep punchcard on the home screen. On an iPhone, the three
 * Add to Home Screen steps; where the browser can install it, its own
 * prompt. Offered when `offerInstall` (install-prompt.ts says on which
 * phones), it opens once the stamp has landed; put away, it stays away for
 * a month on this browser.
 */
export default function InstallSheet({
    offerInstall,
}: {
    offerInstall: boolean;
}) {
    const { t } = useTranslation();
    const prompt = useInstallPrompt();
    const [askedLately] = useState(snoozedUntil);
    const [standalone] = useState(isStandalone);
    const [shownAt] = useState(() => Date.now());
    const [open, setOpen] = useState(false);
    const offer = installOffer({
        offerInstall,
        standalone,
        userAgent: navigator.userAgent,
        hasPrompt: prompt !== null,
        snoozedUntil: askedLately,
        now: shownAt,
    });

    useEffect(() => {
        if (offer === null) {
            return;
        }

        const timer = window.setTimeout(() => setOpen(true), OPENS_AFTER_MS);

        return () => window.clearTimeout(timer);
    }, [offer]);

    const putAway = () => {
        snooze(Date.now());
        setOpen(false);
    };

    return (
        <Sheet
            open={open && offer !== null}
            onOpenChange={(isOpen) => (isOpen ? setOpen(true) : putAway())}
        >
            <SheetContent side="bottom" className="mx-auto max-w-md">
                <SheetHeader>
                    <SheetTitle>
                        {/* Clear of the close button. */}
                        <span className="block pe-8">
                            {t('Keep punchcard on your home screen')}
                        </span>
                    </SheetTitle>
                    <SheetDescription>
                        {t('Your cards in one tap, even offline.')}
                    </SheetDescription>
                </SheetHeader>

                {offer === 'ios' ? (
                    <ol className="flex flex-col gap-3 px-4">
                        <Step number={1} icon={<Share />}>
                            {t('Tap Share in the toolbar')}
                        </Step>
                        <Step number={2} icon={<SquarePlus />}>
                            {t('Choose Add to Home Screen')}
                        </Step>
                        <Step number={3}>{t('Tap Add')}</Step>
                    </ol>
                ) : null}

                <SheetFooter>
                    {offer === 'android' ? (
                        <>
                            <Button
                                size="lg"
                                onClick={() => {
                                    setOpen(false);
                                    void install();
                                }}
                            >
                                <Download aria-hidden="true" />
                                {t('Install')}
                            </Button>
                            <Button size="lg" variant="ghost" onClick={putAway}>
                                {t('Not now')}
                            </Button>
                        </>
                    ) : (
                        <Button size="lg" onClick={putAway}>
                            {t('Got it')}
                        </Button>
                    )}
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

function Step({
    number,
    icon,
    children,
}: {
    number: number;
    icon?: ReactNode;
    children: ReactNode;
}) {
    return (
        <li className="flex items-center gap-3">
            <span
                aria-hidden="true"
                className="flex size-8 shrink-0 items-center justify-center rounded-full bg-stamp font-semibold text-stamp-foreground tabular-nums"
            >
                {number}
            </span>
            <span className="flex-1">{children}</span>
            {icon ? (
                <span
                    aria-hidden="true"
                    className="text-muted-foreground [&_svg]:size-5"
                >
                    {icon}
                </span>
            ) : null}
        </li>
    );
}

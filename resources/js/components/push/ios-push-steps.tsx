import { IosInstallSteps } from '@/components/tap/install-sheet';
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

/**
 * "How to add it" on an iPhone in Safari (CHW-41, 11a and 11c): the C13
 * steps to the home screen, where the installed app can have notifications.
 */
export default function IosPushSteps({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="bottom" className="mx-auto max-w-md">
                <SheetHeader>
                    <SheetTitle>
                        <span className="block pe-8 font-display text-2xl leading-tight font-bold">
                            {t('Keep punchcard on your home screen')}
                        </span>
                    </SheetTitle>
                    <SheetDescription>
                        <span className="block text-base">
                            {t(
                                'Then open it from there to turn notifications on.',
                            )}
                        </span>
                    </SheetDescription>
                </SheetHeader>
                <IosInstallSteps />
                <SheetFooter>
                    <Button size="lg" onClick={() => onOpenChange(false)}>
                        {t('Got it')}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

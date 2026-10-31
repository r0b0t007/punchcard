import { BellRing, Share } from 'lucide-react';
import { useEffect, useState } from 'react';
import IosPushSteps from '@/components/push/ios-push-steps';
import type { PushSettings } from '@/components/push/phone-notifications';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import {
    askHeldAway,
    askPutAwayUntil,
    asksFor,
    putAskAway,
    usePush,
} from '@/lib/push';

/**
 * The ask under a moment (CHW-41, design 11a): a reward just unlocked (C3)
 * or a place just followed (C6). "Turn on notifications" opens the
 * browser's own dialog; "Not now" puts it away for a month on this browser.
 * On an iPhone in Safari it points to the home screen instead ("How to add
 * it", the C13 steps). Nothing at all where the phone can't have them, has
 * them already, or said no; worked out on the phone, never on the server.
 */
export default function PushAsk({
    push,
    title,
    body,
}: {
    push: PushSettings;
    title: string;
    /** What turning them on brings; on an iPhone in Safari, the home screen's line instead. */
    body: string;
}) {
    const { t } = useTranslation();
    const { state, busy, failed, turnOn } = usePush(
        push.publicKey,
        push.phones,
    );
    // Unknown until read on the phone: held away until then.
    const [heldAway, setHeldAway] = useState(true);
    const [steps, setSteps] = useState(false);

    useEffect(() => {
        setHeldAway(askHeldAway(askPutAwayUntil(), Date.now()));
    }, []);

    if (heldAway || !asksFor(state)) {
        return null;
    }

    const ios = state === 'ios-needs-install';
    const putAway = () => {
        putAskAway(Date.now());
        setHeldAway(true);
    };

    return (
        <div
            className="flex flex-col gap-3 rounded-card border bg-card p-4"
            data-test="push-ask"
        >
            <div className="flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                    {ios ? (
                        <Share className="size-4" aria-hidden />
                    ) : (
                        <BellRing className="size-4" aria-hidden />
                    )}
                </span>
                <div className="grid gap-0.5">
                    <p className="text-md font-semibold">{title}</p>
                    <p className="text-sm text-muted-foreground">
                        {ios
                            ? t(
                                  'On iPhone, notifications come through punchcard on your home screen. Add it, then turn them on there.',
                              )
                            : body}
                    </p>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                {ios ? (
                    <Button onClick={() => setSteps(true)}>
                        {t('How to add it')}
                    </Button>
                ) : (
                    <Button
                        disabled={busy}
                        onClick={() => void turnOn()}
                        data-test="push-ask-turn-on"
                    >
                        {t('Turn on notifications')}
                    </Button>
                )}
                <Button
                    variant="ghost"
                    onClick={putAway}
                    data-test="push-ask-not-now"
                >
                    {t('Not now')}
                </Button>
            </div>
            {failed ? (
                <p role="alert" className="text-sm text-destructive">
                    {t(
                        "We couldn't change this phone's notifications. Try again later.",
                    )}
                </p>
            ) : null}
            <IosPushSteps open={steps} onOpenChange={setSteps} />
        </div>
    );
}

import { router } from '@inertiajs/react';
import { Bell, BellOff, Share } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import IosPushSteps from '@/components/push/ios-push-steps';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useTranslation } from '@/hooks/use-translation';
import { usePush } from '@/lib/push';
import type { PushState } from '@/lib/push';
import { notifications } from '@/routes/privacy';

export type PushSettings = {
    /** The app's VAPID key; null: no notifications offered at all. */
    publicKey: string | null;
    /** The account's "Rewards ready", for every phone. */
    rewards: boolean;
    /** The account's phones, as SHA-256 of their endpoints: this one is "On" only if listed. */
    phones: string[];
};

/**
 * Settings' Notifications (CHW-41, design 11c): whether this phone gets
 * them, with the one button its state allows, and "Rewards ready" for the
 * account. Offers stay with the places followed, below.
 */
export default function PhoneNotifications({ push }: { push: PushSettings }) {
    const { t } = useTranslation();
    const { state, busy, failed, turnOn, turnOff } = usePush(
        push.publicKey,
        push.phones,
    );
    const [saving, setSaving] = useState(false);
    const [steps, setSteps] = useState(false);

    if (push.publicKey === null) {
        return null;
    }

    const shown = describe(state, t);

    return (
        <div className="space-y-4" data-test="phone-notifications">
            <Heading variant="small" title={t('Notifications')} />

            <div className="flex flex-col gap-3 rounded-2xl border bg-card p-4">
                <div className="flex items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                        {state === 'ios-needs-install' ? (
                            <Share className="size-4" aria-hidden />
                        ) : state === 'on' ? (
                            <Bell className="size-4" aria-hidden />
                        ) : (
                            <BellOff className="size-4" aria-hidden />
                        )}
                    </span>
                    <div className="grid flex-1 gap-0.5">
                        <span className="text-md font-semibold">
                            {t('This phone')}
                        </span>
                        <span
                            className="text-sm text-muted-foreground"
                            data-test="push-state"
                        >
                            {shown.state}
                        </span>
                    </div>
                    {shown.chip ? (
                        <Badge variant="secondary">{shown.chip}</Badge>
                    ) : null}
                </div>

                {state === 'off' ? (
                    <Button
                        size="lg"
                        disabled={busy}
                        onClick={() => void turnOn()}
                        data-test="push-turn-on"
                    >
                        {t('Turn on notifications')}
                    </Button>
                ) : state === 'on' ? (
                    <Button
                        size="lg"
                        variant="outline"
                        disabled={busy}
                        onClick={() => void turnOff()}
                        data-test="push-turn-off"
                    >
                        {t('Turn off')}
                    </Button>
                ) : state === 'ios-needs-install' ? (
                    <Button
                        size="lg"
                        variant="outline"
                        onClick={() => setSteps(true)}
                    >
                        {t('How to add it')}
                    </Button>
                ) : null}

                {failed ? (
                    <p role="alert" className="text-sm text-destructive">
                        {t(
                            "We couldn't change this phone's notifications. Try again later.",
                        )}
                    </p>
                ) : null}

                <Separator />

                <div className="flex items-start gap-3">
                    <Checkbox
                        id="push-rewards"
                        checked={push.rewards}
                        disabled={saving}
                        onCheckedChange={(checked) =>
                            router.patch(
                                notifications.url(),
                                { rewards: checked === true },
                                {
                                    preserveScroll: true,
                                    preserveState: true,
                                    onStart: () => setSaving(true),
                                    onFinish: () => setSaving(false),
                                },
                            )
                        }
                        data-test="push-rewards"
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="push-rewards">
                            {t('Rewards ready')}
                        </Label>
                        <p className="text-sm text-muted-foreground">
                            {t('When you unlock a reward.')}
                        </p>
                    </div>
                </div>
            </div>

            <p className="text-sm text-muted-foreground">
                {t('Offers come from the places you follow, below.')}
            </p>

            <IosPushSteps open={steps} onOpenChange={setSteps} />
        </div>
    );
}

/** What this phone's state says, and its chip. */
function describe(
    state: PushState | null,
    t: (key: string) => string,
): { state: string; chip: string | null } {
    switch (state) {
        case 'on':
            return {
                state: t('Notifications reach this phone.'),
                chip: t('On'),
            };
        case 'off':
            return {
                state: t('Turn them on to hear about rewards and offers.'),
                chip: t('Off'),
            };
        case 'blocked':
            return {
                state: t(
                    "Blocked in this browser's settings. Allow notifications for punchcard there.",
                ),
                chip: t('Blocked'),
            };
        case 'ios-needs-install':
            return {
                state: t(
                    'On iPhone, add punchcard to your home screen, then open it from there.',
                ),
                chip: t('Not here'),
            };
        case 'unsupported':
            return {
                state: t("This browser can't show notifications."),
                chip: t('Not here'),
            };
        default:
            return { state: '', chip: null };
    }
}

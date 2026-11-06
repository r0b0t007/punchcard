import { Head, router, useForm } from '@inertiajs/react';
import { Cake, Gift, HeartHandshake, Minus, Moon, Plus } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Counter } from '@/components/campaigns/counter';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { StampDot } from '@/components/wordmark';
import { useTranslation } from '@/hooks/use-translation';
import { clip, notificationTitle } from '@/lib/campaigns';
import { cn, typedDir } from '@/lib/utils';
import { index, update } from '@/routes/automations';

type Trigger = 'reward_reminder' | 'birthday' | 'win_back';

type Automation = {
    trigger: Trigger;
    name: string;
    active: boolean;
    days: number;
    stamps: number;
    /** The owner's own, or null for ours. */
    title: string | null;
    body: string | null;
    /** Ours, in the owner's language: the preview, and "Use our message instead". */
    ours: { title: string; body: string };
    /** People it reached in the last sentDays days. */
    sent: number;
};

type Range = { min: number; max: number };

type Limits = { days: Range; stamps: Range; title: number; body: number };

const ICONS: Record<Trigger, LucideIcon> = {
    reward_reminder: Gift,
    birthday: Cake,
    win_back: HeartHandshake,
};

/**
 * Automations (CHW-46, B11, design 18a): one card per automation, in the
 * weekly cap's order, side by side on a computer and stacked on a phone.
 * Each has its switch, its one setting (the reminder's wait, the
 * birthday's stamps), the message as a customer gets it (ours, in their
 * language, or the owner's as written) and how many people it reached in
 * 30 days. A switch or setting saves as it changes; "Edit the message"
 * opens the editor (18c). The owner's, never staff's, while the business is
 * allowed automations.
 */
export default function Automations({
    business,
    automations,
    limits,
    sentDays,
}: {
    business: string;
    automations: Automation[];
    limits: Limits;
    sentDays: number;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<Automation | null>(null);

    return (
        <>
            <Head title={t('Automations')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4">
                <Heading
                    title={t('Automations')}
                    description={t(
                        'Messages that go out on their own, to the people who follow you for offers. Each is off until you turn it on.',
                    )}
                />

                <Alert data-test="automation-rules">
                    <Moon aria-hidden="true" />
                    <AlertDescription>
                        {t(
                            "Never between 22:00 and 08:00 in :business's time, and at most one of these a week per customer: a reward reminder first, then a birthday, then a win-back.",
                            { business },
                        )}
                    </AlertDescription>
                </Alert>

                <div className="grid gap-4 lg:grid-cols-3">
                    {automations.map((automation) => (
                        <AutomationCard
                            key={automation.trigger}
                            automation={automation}
                            business={business}
                            limits={limits}
                            sentDays={sentDays}
                            onEdit={() => setEditing(automation)}
                        />
                    ))}
                </div>
            </div>

            <MessageEditor
                automation={editing}
                business={business}
                limits={limits}
                onClose={() => setEditing(null)}
            />
        </>
    );
}

/** What a save sends: the automation as it is, with the changes. */
function saved(automation: Automation, changes: Partial<Automation>) {
    const next = { ...automation, ...changes };

    return {
        active: next.active,
        title: next.title,
        body: next.body,
        ...(next.trigger === 'reward_reminder' ? { days: next.days } : {}),
        ...(next.trigger === 'birthday' ? { stamps: next.stamps } : {}),
    };
}

/** How long the stepper waits after the last tap before it saves: one save for 7 to 60 days, not 53. */
const SETTING_SAVE_MS = 500;

function AutomationCard({
    automation: stored,
    business,
    limits,
    sentDays,
    onEdit,
}: {
    automation: Automation;
    business: string;
    limits: Limits;
    sentDays: number;
    onEdit: () => void;
}) {
    const { t } = useTranslation();
    const Icon = ICONS[stored.trigger];
    // What the owner changed and the server hasn't confirmed yet: shown at once, and sent whole with every save,
    // so quick taps add up instead of each starting again from the page as it was.
    const [changes, setChanges] = useState<Partial<Automation>>({});
    const [saving, setSaving] = useState(false);
    const latest = useRef({ stored, changes, version: 0 });
    const timer = useRef<number | undefined>(undefined);
    latest.current.stored = stored;
    const automation = { ...stored, ...changes };

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const send = () => {
        window.clearTimeout(timer.current);
        const sent = latest.current.version;

        router.patch(
            update(stored.trigger),
            saved(latest.current.stored, latest.current.changes),
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                // Refused (a title too long since the business was renamed, the limit): say why, show it as stored.
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ??
                            t('That change was not saved.'),
                    ),
                onFinish: () => {
                    setSaving(false);

                    // Nothing changed since: the page's props are the truth again.
                    if (latest.current.version === sent) {
                        latest.current.changes = {};
                        setChanges({});
                    }
                },
            },
        );
    };
    const change = (next: Partial<Automation>, later = false) => {
        latest.current.changes = { ...latest.current.changes, ...next };
        latest.current.version++;
        setChanges(latest.current.changes);
        window.clearTimeout(timer.current);

        if (later) {
            timer.current = window.setTimeout(send, SETTING_SAVE_MS);
        } else {
            send();
        }
    };
    const descriptions: Record<Trigger, string> = {
        reward_reminder: t(
            "A customer has a reward waiting here and hasn't used it.",
        ),
        birthday: t(
            'Stamps on their card on their birthday, and a message. For customers who gave their birthday.',
        ),
        win_back: t(
            'A regular stops coming: the day they go quiet, as on your Customers page.',
        ),
    };
    const own = automation.title !== null || automation.body !== null;

    return (
        <Card data-test={`automation-${automation.trigger}`}>
            <CardContent>
                <div className="flex h-full flex-col gap-4">
                    <div className="flex items-start gap-3">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-stamp/20">
                            <Icon className="size-4" aria-hidden="true" />
                        </span>
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <h3 className="font-semibold">{automation.name}</h3>
                            <p className="text-sm text-pretty text-muted-foreground">
                                {descriptions[automation.trigger]}
                            </p>
                        </div>
                        <Switch
                            checked={automation.active}
                            disabled={saving}
                            onCheckedChange={(active) => change({ active })}
                            aria-label={automation.name}
                            data-test={`automation-${automation.trigger}-switch`}
                        />
                    </div>

                    <div
                        className={cn(
                            'flex flex-col gap-4 transition-opacity',
                            !automation.active && 'opacity-60',
                        )}
                    >
                        <Setting
                            automation={automation}
                            limits={limits}
                            onChange={(next) => change(next, true)}
                        />

                        <div className="flex flex-col gap-1.5">
                            <NotificationPreview
                                title={
                                    automation.title === null
                                        ? automation.ours.title
                                        : notificationTitle(
                                              business,
                                              automation.title,
                                          )
                                }
                                body={automation.body ?? automation.ours.body}
                            />
                            <span className="text-xs text-muted-foreground">
                                {own
                                    ? t('Your message, sent as written')
                                    : t(
                                          "Our message, in each customer's language",
                                      )}
                            </span>
                        </div>
                    </div>

                    <div className="mt-auto flex items-center justify-between gap-3 border-t pt-3 text-sm">
                        <Button
                            variant="link"
                            size="sm"
                            onClick={onEdit}
                            data-test={`automation-${automation.trigger}-edit`}
                        >
                            {t('Edit the message')}
                        </Button>
                        <span className="text-muted-foreground">
                            {automation.sent === 0
                                ? t('Not sent yet')
                                : t(
                                      ':count person in :days days|:count people in :days days',
                                      {
                                          count: automation.sent,
                                          days: sentDays,
                                      },
                                  )}
                        </span>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

/** The automation's one setting: the reminder's wait, the birthday's stamps; the win-back follows the quiet rule. */
function Setting({
    automation,
    limits,
    onChange,
}: {
    automation: Automation;
    limits: Limits;
    onChange: (changes: Partial<Automation>) => void;
}) {
    const { t } = useTranslation();

    if (automation.trigger === 'win_back') {
        return (
            <div className="flex items-center justify-between gap-3 text-sm">
                <span>{t('When they go quiet')}</span>
                <span className="text-end whitespace-nowrap text-muted-foreground">
                    {t('Their usual gap × 2')}
                </span>
            </div>
        );
    }

    const days = automation.trigger === 'reward_reminder';
    const value = days ? automation.days : automation.stamps;
    const range = days ? limits.days : limits.stamps;
    const set = (next: number) =>
        onChange(days ? { days: next } : { stamps: next });

    return (
        <div className="flex items-center justify-between gap-3 text-sm">
            <span id={`${automation.trigger}-setting`}>
                {days ? t('After it waits') : t('Stamps on their card')}
            </span>
            <div
                role="group"
                aria-labelledby={`${automation.trigger}-setting`}
                className="flex items-center gap-1"
            >
                <Button
                    variant="outline"
                    size="icon"
                    aria-label={t('One fewer')}
                    disabled={value <= range.min}
                    onClick={() => set(value - 1)}
                >
                    <Minus aria-hidden="true" />
                </Button>
                <span
                    className="min-w-20 text-center font-semibold tabular-nums"
                    aria-live="polite"
                    data-test={`automation-${automation.trigger}-value`}
                >
                    {days
                        ? t(':count day|:count days', { count: value })
                        : t(':count stamp|:count stamps', { count: value })}
                </span>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label={t('One more')}
                    disabled={value >= range.max}
                    onClick={() => set(value + 1)}
                >
                    <Plus aria-hidden="true" />
                </Button>
            </div>
        </div>
    );
}

/** The message as a customer's phone shows it: the punchcard tile, the title and the message. */
function NotificationPreview({ title, body }: { title: string; body: string }) {
    return (
        <div className="flex items-start gap-2.5 rounded-xl bg-secondary p-3">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary">
                <StampDot />
            </span>
            <span className="flex min-w-0 flex-col gap-0.5">
                <b className="text-sm font-semibold break-words">
                    <bdi>{title}</bdi>
                </b>
                <span className="text-sm break-words">
                    <bdi>{body}</bdi>
                </span>
            </span>
        </div>
    );
}

/** Editing a message (18c): the owner's title (beside the business's name) and message, or back to ours. */
function MessageEditor({
    automation,
    business,
    limits,
    onClose,
}: {
    automation: Automation | null;
    business: string;
    limits: Limits;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const form = useForm({ title: '', body: '' });
    const { data, setData, processing, errors } = form;
    const [opened, setOpened] = useState<Trigger | null>(null);

    // A fresh form each time a message is opened: the owner's words, or empty for ours.
    if (automation !== null && opened !== automation.trigger) {
        setOpened(automation.trigger);
        setData({ title: automation.title ?? '', body: automation.body ?? '' });
        // Errors from the message last saved belong to it, not to this one.
        form.clearErrors();
    }

    if (automation === null && opened !== null) {
        setOpened(null);
    }

    const submit = (ours: boolean) => {
        if (automation === null) {
            return;
        }

        form.transform((typed) =>
            saved(automation, {
                title: ours || typed.title.trim() === '' ? null : typed.title,
                body: ours || typed.body.trim() === '' ? null : typed.body,
            }),
        );
        form.patch(update(automation.trigger).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const previewTitle =
        automation === null
            ? ''
            : data.title.trim() === ''
              ? automation.ours.title
              : notificationTitle(business, data.title);
    const previewBody =
        automation === null
            ? ''
            : data.body.trim() === ''
              ? automation.ours.body
              : data.body;

    return (
        <Dialog
            open={automation !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent data-test="automation-editor">
                <DialogTitle>{automation?.name}</DialogTitle>
                <DialogDescription>
                    {t(
                        'What a customer reads. Leave it to us and they get it in their language.',
                    )}
                </DialogDescription>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit(false);
                    }}
                >
                    <div className="grid gap-2">
                        <div className="flex items-baseline justify-between gap-2">
                            <Label htmlFor="automation-title">
                                {t('Title')}
                            </Label>
                            <Counter value={data.title} max={limits.title} />
                        </div>
                        <Input
                            id="automation-title"
                            dir={typedDir(data.title)}
                            placeholder={automation?.ours.title}
                            value={data.title}
                            onChange={(event) =>
                                setData(
                                    'title',
                                    clip(event.target.value, limits.title),
                                )
                            }
                        />
                        <InputError message={errors.title} />
                    </div>
                    <div className="grid gap-2">
                        <div className="flex items-baseline justify-between gap-2">
                            <Label htmlFor="automation-body">
                                {t('Message')}
                            </Label>
                            <Counter value={data.body} max={limits.body} />
                        </div>
                        <Textarea
                            id="automation-body"
                            dir={typedDir(data.body)}
                            rows={3}
                            placeholder={automation?.ours.body}
                            value={data.body}
                            onChange={(event) =>
                                setData(
                                    'body',
                                    clip(event.target.value, limits.body),
                                )
                            }
                        />
                        <InputError message={errors.body} />
                    </div>
                    <NotificationPreview
                        title={previewTitle}
                        body={previewBody}
                    />
                    <p className="text-sm text-pretty text-muted-foreground">
                        {t(
                            "Your words are sent as written, not translated. Your business's name always comes first.",
                        )}
                    </p>
                    <Button
                        type="button"
                        variant="link"
                        size="sm"
                        className="self-start"
                        disabled={processing}
                        onClick={() => submit(true)}
                        data-test="automation-use-ours"
                    >
                        {t('Use our message instead')}
                    </Button>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                {t('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="automation-save"
                        >
                            {t('Save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

Automations.layout = {
    breadcrumbs: [
        {
            title: 'Automations',
            href: index(),
        },
    ],
};

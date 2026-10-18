import { Form, Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { localDate } from '@/lib/local-moment';
import { monogramOf } from '@/components/loyalty-card/monogram';
import { edit, follow, marketing as setMarketing } from '@/routes/privacy';
import { send } from '@/routes/verification';

type Program = {
    enrollmentId: number;
    organization: string;
    logoUrl: string | null;
    following: boolean;
    /** The follow's last change (ISO 8601); null: never followed. */
    changedAt: string | null;
    /** A closed program can be left, never joined. */
    canFollow: boolean;
};

type Marketing = {
    on: boolean;
    /** When news was turned on, or off (ISO 8601); null: never chosen. */
    since: string | null;
    /** Turning news on needs a confirmed email; turning it off never does. */
    mustVerify: boolean;
};

/**
 * C12 Privacy (CHW-58, review 7a): what punchcard may send the customer.
 * A checkbox, as on a card's page (C6), each state with its date in the
 * device's timezone (a customer has no site). It waits for one change to
 * land before taking the next.
 */
export default function Privacy({
    marketing,
    programs,
    status,
}: {
    marketing: Marketing;
    programs: Program[];
    status?: string;
}) {
    const { t, locale } = useTranslation();
    const { errors } = usePage().props;
    const [saving, setSaving] = useState(false);
    const since =
        marketing.since === null
            ? null
            : localDate(locale, marketing.since, {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              });

    return (
        <>
            <Head title={t('Privacy settings')} />

            <h1 className="sr-only">{t('Privacy settings')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('News and offers')}
                    description={t(
                        'What we may email you. You can change it at any time.',
                    )}
                />

                <div className="flex items-start gap-3">
                    <Checkbox
                        id="marketing"
                        checked={marketing.on}
                        disabled={
                            saving || (marketing.mustVerify && !marketing.on)
                        }
                        onCheckedChange={(checked) =>
                            router.patch(
                                setMarketing.url(),
                                { marketing: checked === true },
                                {
                                    preserveScroll: true,
                                    onStart: () => setSaving(true),
                                    onFinish: () => setSaving(false),
                                },
                            )
                        }
                        data-test="marketing-consent"
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="marketing">
                            {t('News from punchcard')}
                        </Label>
                        <p className="text-sm text-muted-foreground">
                            {since === null
                                ? t('Off')
                                : marketing.on
                                  ? t('On since :date', { date: since })
                                  : t('Off since :date', { date: since })}
                        </p>
                        {marketing.mustVerify ? (
                            <Form
                                {...send.form()}
                                options={{ preserveScroll: true }}
                                className="text-sm text-muted-foreground"
                            >
                                {({ processing }) => (
                                    <>
                                        {t(
                                            'Confirm your email first to get news.',
                                        )}{' '}
                                        <button
                                            type="submit"
                                            className="font-medium text-foreground underline underline-offset-4"
                                            disabled={processing}
                                            data-test="resend-verification"
                                        >
                                            {t('Send the link again')}
                                        </button>
                                    </>
                                )}
                            </Form>
                        ) : null}
                        {status === 'verification-link-sent' ? (
                            <p
                                role="status"
                                className="text-sm font-medium text-success"
                            >
                                {t('A new link is on its way.')}
                            </p>
                        ) : null}
                        <InputError message={errors.marketing} />
                    </div>
                </div>

                <div className="space-y-4">
                    <p className="text-sm font-medium">
                        {t('Offers from places you follow')}
                    </p>
                    {programs.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'No places yet: each one you collect stamps at appears here.',
                            )}
                        </p>
                    ) : (
                        <ul className="space-y-4">
                            {programs.map((program) => (
                                <ProgramRow
                                    key={program.enrollmentId}
                                    program={program}
                                    mustVerify={marketing.mustVerify}
                                />
                            ))}
                        </ul>
                    )}
                    <InputError message={errors.follow} />
                </div>
            </div>
        </>
    );
}

/**
 * A place's offers, followed or not since when (the same Follow as on its
 * card's page, C6). Joining needs a confirmed email and an open program;
 * leaving never does. It waits for one change before taking the next.
 */
function ProgramRow({
    program,
    mustVerify,
}: {
    program: Program;
    mustVerify: boolean;
}) {
    const { t, locale } = useTranslation();
    const [saving, setSaving] = useState(false);
    const [logoFailed, setLogoFailed] = useState(false);
    const id = `follow-${program.enrollmentId}`;
    const changed =
        program.changedAt === null
            ? null
            : localDate(locale, program.changedAt, {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              });
    const state = program.following
        ? t('Following since :date', { date: changed ?? '' })
        : !program.canFollow
          ? t('Closed: no more offers')
          : changed === null
            ? t('Off')
            : t('Off since :date', { date: changed });

    return (
        <li className="flex items-center gap-3" data-test="privacy-program">
            <span className="grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg bg-secondary font-semibold">
                {program.logoUrl && !logoFailed ? (
                    <img
                        src={program.logoUrl}
                        alt=""
                        onError={() => setLogoFailed(true)}
                        className="size-full object-cover"
                    />
                ) : (
                    monogramOf(program.organization)
                )}
            </span>
            <div className="grid min-w-0 flex-1 gap-0.5">
                <Label htmlFor={id}>
                    <span className="truncate">{program.organization}</span>
                </Label>
                <p className="text-sm text-muted-foreground">{state}</p>
            </div>
            <Checkbox
                id={id}
                checked={program.following}
                disabled={
                    saving ||
                    (!program.following && (!program.canFollow || mustVerify))
                }
                onCheckedChange={(checked) =>
                    router.patch(
                        follow.url(program.enrollmentId),
                        { follow: checked === true },
                        {
                            preserveScroll: true,
                            onStart: () => setSaving(true),
                            onFinish: () => setSaving(false),
                        },
                    )
                }
            />
        </li>
    );
}

Privacy.layout = {
    breadcrumbs: [
        {
            title: 'Privacy settings',
            href: edit(),
        },
    ],
};

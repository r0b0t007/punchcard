import { Form, Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { edit, marketing as setMarketing } from '@/routes/privacy';
import { send } from '@/routes/verification';

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
    status,
}: {
    marketing: Marketing;
    status?: string;
}) {
    const { t, locale } = useTranslation();
    const { errors } = usePage().props;
    const [saving, setSaving] = useState(false);
    const since =
        marketing.since === null
            ? null
            : new Intl.DateTimeFormat(`${locale}-u-nu-latn`, {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              }).format(new Date(marketing.since));

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
                        {marketing.mustVerify && !marketing.on ? (
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
            </div>
        </>
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

import { Head, Link, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { calendarDate } from '@/lib/local-moment';
import { edit, marketing as setMarketing } from '@/routes/privacy';
import { notice as verifyEmail } from '@/routes/verification';

type Marketing = {
    on: boolean;
    /** The day (Y-m-d, UTC) news was turned on, or off; null: never chosen. */
    since: string | null;
    /** Turning news on needs a confirmed email; turning it off never does. */
    mustVerify: boolean;
};

/**
 * C12 Privacy (CHW-58, review 7a): what punchcard may send the customer.
 * A checkbox, as on a card's page (C6), each state with its date.
 */
export default function Privacy({ marketing }: { marketing: Marketing }) {
    const { t, locale } = useTranslation();
    const { errors } = usePage().props;
    const since =
        marketing.since === null
            ? null
            : calendarDate(locale, marketing.since, {
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
                        disabled={marketing.mustVerify && !marketing.on}
                        onCheckedChange={(checked) =>
                            router.patch(
                                setMarketing.url(),
                                { marketing: checked === true },
                                { preserveScroll: true },
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
                            <p className="text-sm text-muted-foreground">
                                {t('Confirm your email first to get news.')}{' '}
                                <Link
                                    href={verifyEmail()}
                                    className="font-medium text-foreground underline underline-offset-4"
                                >
                                    {t('Send the link again')}
                                </Link>
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

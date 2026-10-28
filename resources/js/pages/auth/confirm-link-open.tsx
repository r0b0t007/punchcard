import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { confirm } from '@/routes/password';

/**
 * A "confirm it's you" link, opened (CHW-23): one button confirms, for the
 * signed-in account it was mailed to. Opening the page spends nothing.
 * Out of date or used already: back to confirming, for a new one.
 */
export default function ConfirmLinkOpen({
    state,
    action,
}: {
    /** Ready to use; or why not: out of date or used, or another account's. */
    state: 'ready' | 'expired' | 'other-account';
    /** The signed link itself, which the button posts to (when ready). */
    action: string | null;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t("Confirm it's you")} />
            {state !== 'ready' || action === null ? (
                <div className="flex flex-col gap-4 text-center">
                    <p className="text-sm text-muted-foreground">
                        {state === 'other-account'
                            ? t(
                                  'This link is for another account: sign in to that one to use it.',
                              )
                            : t('This link has expired or was used already.')}
                    </p>
                    <Button size="lg" asChild className="w-full">
                        <Link href={confirm()}>{t('Get a new link')}</Link>
                    </Button>
                </div>
            ) : (
                <Form action={action} method="post">
                    {({ processing }) => (
                        <Button
                            size="lg"
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="confirm-link-button"
                        >
                            {processing && <Spinner />}
                            {t('Confirm')}
                        </Button>
                    )}
                </Form>
            )}
        </>
    );
}

ConfirmLinkOpen.layout = {
    title: "Confirm it's you",
    description: 'One tap and you can carry on.',
};

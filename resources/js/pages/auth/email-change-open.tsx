import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { start } from '@/routes';

/**
 * The link mailed to a new address asked for in settings (CHW-23), opened:
 * one button makes it the account's address (opening the page spends
 * nothing). Then: done, or another account took it meanwhile; or the link
 * is out of date, used, replaced by a later request or cancelled.
 */
export default function EmailChangeOpen({
    state,
    action,
}: {
    state: 'ready' | 'changed' | 'taken' | 'expired';
    /** The signed link itself, which the button posts to (when ready). */
    action: string | null;
}) {
    const { t } = useTranslation();

    const message = {
        ready: null,
        changed: t('Done: this is now the address of your punchcard account.'),
        taken: t(
            'Another account uses this address now, so yours keeps its own.',
        ),
        expired: t(
            'This link has expired, was used already or was replaced by a later one.',
        ),
    }[state];

    return (
        <>
            <Head title={t('Your new address')} />
            {message !== null || action === null ? (
                <div className="flex flex-col gap-4 text-center">
                    <p className="text-sm text-muted-foreground">{message}</p>
                    <Button asChild className="w-full">
                        <Link href={start()}>{t('Go to punchcard')}</Link>
                    </Button>
                </div>
            ) : (
                <Form action={action} method="post">
                    {({ processing }) => (
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="email-change-button"
                        >
                            {processing && <Spinner />}
                            {t('Use this address')}
                        </Button>
                    )}
                </Form>
            )}
        </>
    );
}

EmailChangeOpen.layout = {
    title: 'Your new address',
    description: 'One tap and your account uses it.',
};

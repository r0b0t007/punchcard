import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { create } from '@/routes/login-link';

/**
 * An email sign-in link, opened (CHW-23): one button signs in. Opening the
 * page spends nothing, so a mail scanner that opens links can't use it up.
 * It says which account it opens (half hidden), so nobody signs in to
 * someone else's from a forwarded link. Out of date or used already: a way
 * to get a new one.
 */
export default function EmailLinkOpen({
    expired,
    action,
    email,
}: {
    expired: boolean;
    /** The signed link itself, which the button posts to. */
    action: string | null;
    /** The account it signs in to, half hidden (s***@example.com). */
    email: string | null;
}) {
    const { t, tn } = useTranslation();

    return (
        <>
            <Head title={t('Sign in to punchcard')} />
            {expired || action === null ? (
                <div className="flex flex-col gap-4 text-center">
                    <p className="text-sm text-muted-foreground">
                        {t('This link has expired or was used already.')}
                    </p>
                    <Button size="lg" asChild className="w-full">
                        <Link href={create()}>{t('Get a new link')}</Link>
                    </Button>
                </div>
            ) : (
                <Form
                    action={action}
                    method="post"
                    className="flex flex-col gap-4"
                >
                    {({ processing }) => (
                        <>
                            {email ? (
                                <p className="text-center text-sm text-muted-foreground">
                                    {tn('Signs you in as :email', {
                                        email: <bdi dir="ltr">{email}</bdi>,
                                    })}
                                </p>
                            ) : null}
                            <Button
                                size="lg"
                                type="submit"
                                className="w-full"
                                disabled={processing}
                                data-test="email-link-sign-in-button"
                            >
                                {processing && <Spinner />}
                                {t('Sign in')}
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </>
    );
}

EmailLinkOpen.layout = {
    title: 'Sign in to punchcard',
    description: 'One tap and you are in.',
};

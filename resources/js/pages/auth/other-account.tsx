import { Form, Head, Link } from '@inertiajs/react';
import { MailWarning } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { start } from '@/routes';

/**
 * A business's email link opened while another account is signed in
 * (CHW-147, design 10e): whom it was sent to (half hidden) and who is
 * signed in. "Sign out" posts to the link itself, which signs out and
 * opens the sign-in page; signing in as the right account comes back to
 * the link. Or stay signed in, and go home.
 */
export default function OtherAccount({
    sentTo,
    signedInAs,
    name,
    action,
}: {
    /** The account it was mailed to, half hidden (s***@example.com). */
    sentTo: string;
    signedInAs: string;
    name: string;
    /** The signed link itself, which "Sign out" posts to. */
    action: string;
}) {
    const { t, tn } = useTranslation();

    return (
        <>
            <Head title={t('This link is for another account')} />
            <div className="flex flex-col items-center gap-4 text-center">
                <MailWarning className="size-10" aria-hidden />
                <h1 className="font-display text-form-title font-bold text-balance">
                    {t('This link is for another account')}
                </h1>
                <p className="text-sm text-pretty text-muted-foreground">
                    {tn(
                        "We sent it to :sentTo. You're signed in as :signedInAs. Sign out, then sign in with the account it was sent to.",
                        {
                            sentTo: <bdi dir="ltr">{sentTo}</bdi>,
                            signedInAs: <bdi dir="ltr">{signedInAs}</bdi>,
                        },
                    )}
                </p>
            </div>
            <div className="flex flex-col gap-2.5">
                <Form action={action} method="post">
                    {({ processing }) => (
                        <Button
                            size="lg"
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="other-account-sign-out"
                        >
                            {processing && <Spinner />}
                            {t('Sign out and use that account')}
                        </Button>
                    )}
                </Form>
                <Button size="lg" variant="outline" asChild className="w-full">
                    <Link href={start()}>
                        {/* One span: the button is a flex row, and tn()'s pieces would each take a column. */}
                        <span className="text-center">
                            {tn('Stay signed in as :name', {
                                name: <bdi>{name}</bdi>,
                            })}
                        </span>
                    </Link>
                </Button>
            </div>
        </>
    );
}

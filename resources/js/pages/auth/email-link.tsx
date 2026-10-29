import { Form, Head } from '@inertiajs/react';
import { MailCheck } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { login } from '@/routes';
import { create, store } from '@/routes/login-link';

/**
 * Signing in with an email link (CHW-23, from C1's "Continue with email"):
 * the address, then "check your inbox", whatever the address (the page
 * never says who has an account).
 */
export default function EmailLink({
    status,
    minutes,
}: {
    status?: string;
    /** How long a link signs in (LoginLink::MINUTES). */
    minutes: number;
}) {
    const { t } = useTranslation();

    if (status === 'login-link-sent') {
        return (
            <>
                <Head title={t('Check your inbox')} />
                <div className="flex flex-col items-center gap-4 text-center">
                    <MailCheck
                        aria-hidden="true"
                        className="size-10 text-success"
                    />
                    <h2 className="font-display text-xl font-bold">
                        {t('Check your inbox')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'We emailed you a sign-in link. It works once, for :minutes minutes.',
                            { minutes },
                        )}
                    </p>
                    {/* A stamp waiting for sign-in stays with this browser (CHW-142): not with the mail app's own. */}
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Open the link in this browser to keep a stamp waiting here.',
                        )}
                    </p>
                    <TextLink href={create()} className="text-sm">
                        {t('Use another email')}
                    </TextLink>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={t('Continue with email')} />
            <Form {...store.form()} className="flex flex-col gap-6">
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">{t('Email address')}</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                autoComplete="email"
                                placeholder="email@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <Button
                            size="lg"
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="email-link-button"
                        >
                            {processing && <Spinner />}
                            {t('Email me a sign-in link')}
                        </Button>
                    </>
                )}
            </Form>

            <div className="text-center text-sm text-muted-foreground">
                {t('Have a password?')}{' '}
                <TextLink href={login()}>{t('Log in')}</TextLink>
            </div>
        </>
    );
}

EmailLink.layout = {
    title: 'Continue with email',
    description: "We'll email you a link to sign in: no password needed.",
};

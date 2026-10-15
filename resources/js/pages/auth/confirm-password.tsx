import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';
import { confirmByEmail } from '@/routes/password';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import PasskeyVerify from '@/components/passkey-verify';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Confirming it's you, where settings ask (RequirePassword): a passkey, then
 * the password; or, for an account without one (signed up by email link,
 * CHW-23), a link mailed to its address, where it may (MayConfirmByEmail).
 * With neither, the page says how to get a password.
 */
export default function ConfirmPassword({
    hasPassword,
    mayConfirmByEmail,
    status,
}: {
    hasPassword: boolean;
    mayConfirmByEmail: boolean;
    status?: string;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Confirm password')} />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label={t('Confirm with passkey')}
                loadingLabel={t('Confirming...')}
                separator={
                    hasPassword
                        ? t('Or confirm with password')
                        : mayConfirmByEmail
                          ? t('Or confirm by email')
                          : undefined
                }
            />

            {hasPassword || mayConfirmByEmail ? null : (
                <p className="text-center text-sm text-muted-foreground">
                    {t(
                        'No passkey here? Sign out, then choose a password with “Forgot your password?” on the sign-in page.',
                    )}
                </p>
            )}

            {!mayConfirmByEmail ? null : (
                <Form {...confirmByEmail.form()} className="space-y-4">
                    {({ processing, errors }) =>
                        status === 'confirm-link-sent' ? (
                            <p className="text-center text-sm text-muted-foreground">
                                {t(
                                    'We emailed you a link. Open it in this browser to carry on.',
                                )}
                            </p>
                        ) : (
                            <>
                                <Button
                                    className="w-full"
                                    disabled={processing}
                                    data-test="confirm-by-email-button"
                                >
                                    {processing && <Spinner />}
                                    {t('Email me a link to confirm')}
                                </Button>
                                <InputError message={errors.email} />
                            </>
                        )
                    }
                </Form>
            )}

            {hasPassword ? (
                <Form {...store.form()} resetOnSuccess={['password']}>
                    {({ processing, errors }) => (
                        <div className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {t('Password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    placeholder={t('Password')}
                                    autoComplete="current-password"
                                    autoFocus
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center">
                                <Button
                                    className="w-full"
                                    disabled={processing}
                                    data-test="confirm-password-button"
                                >
                                    {processing && <Spinner />}
                                    {t('Confirm password')}
                                </Button>
                            </div>
                        </div>
                    )}
                </Form>
            ) : null}
        </>
    );
}

ConfirmPassword.layout = {
    title: "Confirm it's you",
    description:
        'This is a secure area of the application. Please confirm it is you before continuing.',
};

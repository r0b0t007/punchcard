import { Form, Head, Link } from '@inertiajs/react';
import ConsentFields from '@/components/consent-fields';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { home, login, logout } from '@/routes';

type State =
    | 'join'
    | 'choose-password'
    | 'sign-up'
    | 'sign-in'
    | 'other-account'
    | 'expired';

/**
 * An invitation to a business's staff, opened (CHW-146). Opening it spends
 * nothing. The invited address joins with one button, choosing a password
 * first if it has none (staff sign in with a password or passkey); a guest
 * signs in to that account (and comes back) or signs up; another account
 * can't use it.
 */
export default function StaffInvitation({
    state,
    businessName,
    locationName,
    email,
    action,
}: {
    state: State;
    businessName: string | null;
    locationName: string | null;
    /** In full for its owner, half hidden for anyone else. */
    email: string | null;
    /** The signed link itself, which the button posts to. */
    action: string | null;
}) {
    const { t, tn } = useTranslation();
    const address = <bdi dir="ltr">{email}</bdi>;

    if (state === 'expired' || action === null) {
        return (
            <>
                <Head title={t('Join the staff')} />
                <div className="flex flex-col gap-4 text-center">
                    <p className="text-sm text-muted-foreground">
                        {t(
                            "This invitation can't be used any more: ask for a new one.",
                        )}
                    </p>
                    <Button asChild className="w-full">
                        <Link href={home()}>{t('Go to punchcard')}</Link>
                    </Button>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={t('Join the staff')} />
            <div className="flex flex-col gap-6">
                <p className="text-center text-sm">
                    {locationName
                        ? t(
                              ':business invites you to join its staff at :location.',
                              {
                                  business: businessName ?? '',
                                  location: locationName,
                              },
                          )
                        : t(':business invites you to join its staff.', {
                              business: businessName ?? '',
                          })}
                </p>

                {state === 'other-account' ? (
                    <div className="flex flex-col gap-4 text-center">
                        <p className="text-sm text-muted-foreground">
                            {tn(
                                'This invitation is for :email: sign out, then open it again.',
                                { email: address },
                            )}
                        </p>
                        <Button asChild variant="outline" className="w-full">
                            <Link href={logout()} as="button">
                                {t('Log out')}
                            </Link>
                        </Button>
                    </div>
                ) : state === 'sign-in' ? (
                    <div className="flex flex-col gap-4 text-center">
                        <p className="text-sm text-muted-foreground">
                            {tn(
                                'Sign in as :email to accept: you come back here.',
                                { email: address },
                            )}
                        </p>
                        <Button asChild className="w-full">
                            <Link href={login()}>{t('Sign in')}</Link>
                        </Button>
                    </div>
                ) : (
                    <Form
                        action={action}
                        method="post"
                        resetOnError={['password', 'password_confirmation']}
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <p className="text-center text-sm text-muted-foreground">
                                    {state === 'sign-up'
                                        ? tn('Create your account for :email', {
                                              email: address,
                                          })
                                        : tn('Joining as :email', {
                                              email: address,
                                          })}
                                </p>

                                {state === 'sign-up' ? (
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            {t('Name')}
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            autoComplete="name"
                                            placeholder={t('Full name')}
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                ) : null}

                                {state === 'sign-up' ||
                                state === 'choose-password' ? (
                                    <>
                                        {state === 'choose-password' ? (
                                            <p className="text-sm text-muted-foreground">
                                                {t(
                                                    'Staff sign in with a password or a passkey: choose a password to join.',
                                                )}
                                            </p>
                                        ) : null}
                                        <div className="grid gap-2">
                                            <Label htmlFor="password">
                                                {t('Password')}
                                            </Label>
                                            <PasswordInput
                                                id="password"
                                                name="password"
                                                required
                                                autoComplete="new-password"
                                                placeholder={t('Password')}
                                            />
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="password_confirmation">
                                                {t('Confirm password')}
                                            </Label>
                                            <PasswordInput
                                                id="password_confirmation"
                                                name="password_confirmation"
                                                required
                                                autoComplete="new-password"
                                                placeholder={t(
                                                    'Confirm password',
                                                )}
                                            />
                                        </div>
                                    </>
                                ) : null}

                                {state === 'sign-up' ? (
                                    <ConsentFields error={errors.adult} />
                                ) : null}

                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing}
                                    data-test="join-staff-button"
                                >
                                    {processing && <Spinner />}
                                    {t('Join the staff')}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

StaffInvitation.layout = {
    title: 'Join the staff',
    description: 'One step and you can stamp cards.',
};

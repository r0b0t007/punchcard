import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { login, register } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
};

/** B1 for the pilot (CHW-31): the owner's account and their business's name; the wizard does the rest. */
export default function RegisterBusiness({ passwordRules }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Create your business account')} />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="business_name">
                                    {t('Business name')}
                                </Label>
                                <Input
                                    id="business_name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="organization"
                                    name="business_name"
                                    maxLength={120}
                                    placeholder={t('Café Hafa')}
                                />
                                <InputError message={errors.business_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="name">{t('Your name')}</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    tabIndex={2}
                                    autoComplete="name"
                                    name="name"
                                    placeholder={t('Full name')}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('Email address')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={3}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {t('Password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder={t('Password')}
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    {t('Confirm password')}
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={5}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder={t('Confirm password')}
                                    passwordrules={passwordRules}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={6}
                                data-test="register-business-button"
                            >
                                {processing && <Spinner />}
                                {t('Create business account')}
                            </Button>
                        </div>

                        <div className="grid gap-1 text-center text-sm text-muted-foreground">
                            <p>
                                {t('Already have an account?')}{' '}
                                <TextLink href={login()} tabIndex={7}>
                                    {t('Log in')}
                                </TextLink>
                            </p>
                            <p>
                                {t('Collecting stamps?')}{' '}
                                <TextLink href={register()} tabIndex={8}>
                                    {t('Create a customer account')}
                                </TextLink>
                            </p>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

RegisterBusiness.layout = {
    title: 'Create your business account',
    description: 'Set up your loyalty card in a few minutes',
};

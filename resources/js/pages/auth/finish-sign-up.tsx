import { Form, Head } from '@inertiajs/react';
import ConsentFields from '@/components/consent-fields';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/sign-up/finish';

/**
 * A new customer's first details, after their first email link (CHW-23):
 * their name, that they are 16 or older (required), and news by email,
 * never ticked for them. No password: the link was enough.
 */
export default function FinishSignUp({ email }: { email: string }) {
    const { t, tn } = useTranslation();

    return (
        <>
            <Head title={t('Welcome to punchcard')} />
            <Form {...store.form()} className="flex flex-col gap-6">
                {({ processing, errors }) => (
                    <>
                        <p className="text-center text-sm text-muted-foreground">
                            {/* The address reads left to right, also in an Arabic sentence. */}
                            {tn('Signing up as :email', {
                                email: (
                                    <bdi dir="ltr" className="inline-block">
                                        {email}
                                    </bdi>
                                ),
                            })}
                        </p>

                        <div className="grid gap-2">
                            <Label htmlFor="name">{t('Name')}</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus
                                autoComplete="name"
                                placeholder={t('Full name')}
                            />
                            <InputError message={errors.name} />
                        </div>

                        <ConsentFields error={errors.adult} />

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="finish-sign-up-button"
                        >
                            {processing && <Spinner />}
                            {t('Create my account')}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

FinishSignUp.layout = {
    title: 'Welcome to punchcard',
    description: 'Tell us your name to finish signing up.',
};

import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { shipping as save, step } from '@/routes/onboarding';

type Props = {
    kit: {
        recipientName: string | null;
        phone: string | null;
        address: string | null;
        city: string | null;
        postalCode: string | null;
    };
};

/** Step 5 (CHW-31, B2): where to ship the stamper kit. Saving it finishes setup. */
export default function OnboardingShipping({ kit }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Stamper kit')} />
            <Form
                {...save.form()}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="recipient_name">
                                {t('Recipient')}
                            </Label>
                            <Input
                                id="recipient_name"
                                name="recipient_name"
                                dir="auto"
                                required
                                autoFocus
                                maxLength={120}
                                autoComplete="name"
                                defaultValue={kit.recipientName ?? ''}
                            />
                            <InputError message={errors.recipient_name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="phone">{t('Phone')}</Label>
                            <Input
                                id="phone"
                                name="phone"
                                type="tel"
                                required
                                maxLength={32}
                                autoComplete="tel"
                                dir="ltr"
                                defaultValue={kit.phone ?? ''}
                                placeholder="+212 6 12 34 56 78"
                            />
                            <InputError message={errors.phone} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="address">{t('Address')}</Label>
                            <Input
                                id="address"
                                name="address"
                                dir="auto"
                                required
                                maxLength={255}
                                autoComplete="street-address"
                                defaultValue={kit.address ?? ''}
                            />
                            <InputError message={errors.address} />
                        </div>

                        <div className="grid grid-cols-3 gap-3">
                            <div className="col-span-2 grid gap-2">
                                <Label htmlFor="city">{t('City')}</Label>
                                <Input
                                    id="city"
                                    name="city"
                                    dir="auto"
                                    required
                                    maxLength={120}
                                    autoComplete="address-level2"
                                    defaultValue={kit.city ?? ''}
                                />
                                <InputError message={errors.city} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="postal_code">
                                    {t('Postal code')}
                                </Label>
                                <Input
                                    id="postal_code"
                                    name="postal_code"
                                    maxLength={16}
                                    autoComplete="postal-code"
                                    dir="ltr"
                                    defaultValue={kit.postalCode ?? ''}
                                />
                                <InputError message={errors.postal_code} />
                            </div>
                        </div>

                        <div className="flex gap-3">
                            <Button variant="secondary" asChild>
                                <Link href={step('card')}>{t('Back')}</Link>
                            </Button>
                            <Button type="submit" className="flex-1">
                                {processing && <Spinner />}
                                {t('Finish setup')}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

OnboardingShipping.layout = {
    title: 'Where should we send your stamper?',
    description: 'We send your free starter kit to this address.',
};

import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { logo as save, step } from '@/routes/onboarding';
import { skip } from '@/routes/onboarding/logo';

type Props = {
    logoUrl: string | null;
};

/** Step 3 (CHW-31, B2): the logo on the card, the QR stand and the Wallet pass. It can wait. */
export default function OnboardingLogo({ logoUrl }: Props) {
    const { t } = useTranslation();
    const [preview, setPreview] = useState<string | null>(logoUrl);

    return (
        <>
            <Head title={t('Logo')} />
            <Form
                {...save.form()}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="flex items-center gap-4">
                            <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-muted">
                                {preview ? (
                                    <img
                                        src={preview}
                                        alt={t('Your logo')}
                                        className="size-full object-contain"
                                    />
                                ) : (
                                    <span className="text-xs text-muted-foreground">
                                        {t('No logo')}
                                    </span>
                                )}
                            </div>
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="logo">{t('Logo')}</Label>
                                <Input
                                    id="logo"
                                    name="logo"
                                    type="file"
                                    required
                                    accept="image/png,image/jpeg,image/webp"
                                    onChange={(event) => {
                                        const file = event.target.files?.[0];
                                        setPreview(
                                            file
                                                ? URL.createObjectURL(file)
                                                : logoUrl,
                                        );
                                    }}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'PNG, JPEG or WebP, up to 2 MB, at least 128 pixels wide.',
                                    )}
                                </p>
                                <InputError message={errors.logo} />
                            </div>
                        </div>

                        <div className="flex gap-3">
                            <Button variant="secondary" asChild>
                                <Link href={step('location')}>{t('Back')}</Link>
                            </Button>
                            <Button type="submit" className="flex-1">
                                {processing && <Spinner />}
                                {t('Continue')}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
            <Form {...skip.form()} className="text-center">
                {({ processing }) => (
                    <Button type="submit" variant="ghost" disabled={processing}>
                        {t('Skip for now')}
                    </Button>
                )}
            </Form>
        </>
    );
}

OnboardingLogo.layout = {
    title: 'Add your logo',
    description: 'It appears on your loyalty card and your QR stand.',
};

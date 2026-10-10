import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { location as save, step } from '@/routes/onboarding';

type Props = {
    location: { name: string | null; address: string | null; timezone: string };
    timezones: string[];
};

/** Step 2 (CHW-31, B2): the first site, where the stamper goes; its timezone sets the local time of stamps. */
export default function OnboardingLocation({ location, timezones }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Location')} />
            <Form
                {...save.form()}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="name">{t('Location name')}</Label>
                            <Input
                                id="name"
                                name="name"
                                dir="auto"
                                required
                                autoFocus
                                maxLength={120}
                                defaultValue={location.name ?? ''}
                            />
                            <InputError message={errors.name} />
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
                                defaultValue={location.address ?? ''}
                                placeholder={t('12 rue de la Plage, Tangier')}
                            />
                            <InputError message={errors.address} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="timezone">{t('Timezone')}</Label>
                            <Select
                                name="timezone"
                                required
                                defaultValue={location.timezone}
                            >
                                <SelectTrigger
                                    id="timezone"
                                    dir="ltr"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="max-h-72">
                                    {timezones.map((timezone) => (
                                        <SelectItem
                                            key={timezone}
                                            value={timezone}
                                            dir="ltr"
                                        >
                                            {timezone}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.timezone} />
                        </div>

                        <div className="flex gap-3">
                            <Button variant="secondary" asChild>
                                <Link href={step('business')}>{t('Back')}</Link>
                            </Button>
                            <Button type="submit" className="flex-1">
                                {processing && <Spinner />}
                                {t('Continue')}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

OnboardingLocation.layout = {
    title: 'Where is your business?',
    description: 'Your stamper goes here. You can add more locations later.',
};

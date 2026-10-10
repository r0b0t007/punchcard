import { Form, Head } from '@inertiajs/react';
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
import { business as save } from '@/routes/onboarding';

type Props = {
    business: { name: string; category: string | null } | null;
    categories: { value: string; label: string }[];
};

/** Step 1 (CHW-31, B2): the business's name and what kind it is. */
export default function OnboardingBusiness({ business, categories }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Your business')} />
            <Form
                {...save.form()}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="name">{t('Business name')}</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus
                                maxLength={120}
                                autoComplete="organization"
                                defaultValue={business?.name ?? ''}
                                placeholder={t('Café Hafa')}
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="category">
                                {t('What kind of business?')}
                            </Label>
                            <Select
                                name="category"
                                required
                                defaultValue={business?.category ?? undefined}
                            >
                                <SelectTrigger id="category" className="w-full">
                                    <SelectValue
                                        placeholder={t('Choose one')}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((category) => (
                                        <SelectItem
                                            key={category.value}
                                            value={category.value}
                                        >
                                            {category.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.category} />
                        </div>

                        <Button type="submit" className="w-full">
                            {processing && <Spinner />}
                            {t('Continue')}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

OnboardingBusiness.layout = {
    title: 'Tell us about your business',
    description: 'Customers see this name on their card.',
};

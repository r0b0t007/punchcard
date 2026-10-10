import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import LoyaltyCard from '@/components/loyalty-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { card as save, step } from '@/routes/onboarding';

type Props = {
    businessName: string;
    logoUrl: string | null;
    brandColor: string | null;
    card: { rewardText: string; stampsRequired: number };
};

/**
 * Step 4 (CHW-31, B2): the first loyalty card, previewed as customers will
 * see it. Its style, colours and rules come with the card builder (CHW-32).
 */
export default function OnboardingCard({
    businessName,
    logoUrl,
    brandColor,
    card,
}: Props) {
    const { t } = useTranslation();
    const [rewardText, setRewardText] = useState(card.rewardText);
    // What the owner typed, as typed; the preview takes a whole number from 5 to 50.
    const [stampsRequired, setStampsRequired] = useState(
        String(card.stampsRequired),
    );
    const typed = Number.parseInt(stampsRequired, 10);
    const previewStamps = Number.isNaN(typed)
        ? card.stampsRequired
        : Math.min(Math.max(typed, 5), 50);

    return (
        <>
            <Head title={t('Card')} />
            <LoyaltyCard
                businessName={businessName}
                logoUrl={logoUrl}
                brandColor={brandColor}
                stampsRequired={previewStamps}
                stampsCollected={Math.min(3, previewStamps)}
                rewardText={rewardText || card.rewardText}
            />
            <Form
                {...save.form()}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="reward_text">{t('Reward')}</Label>
                            <Input
                                id="reward_text"
                                name="reward_text"
                                dir="auto"
                                required
                                maxLength={120}
                                value={rewardText}
                                onChange={(event) =>
                                    setRewardText(event.target.value)
                                }
                            />
                            <InputError message={errors.reward_text} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="stamps_required">
                                {t('Stamps to earn it')}
                            </Label>
                            <Input
                                id="stamps_required"
                                name="stamps_required"
                                type="number"
                                required
                                min={5}
                                max={50}
                                step={1}
                                inputMode="numeric"
                                value={stampsRequired}
                                onChange={(event) =>
                                    setStampsRequired(event.target.value)
                                }
                            />
                            <InputError message={errors.stamps_required} />
                        </div>

                        <div className="flex gap-3">
                            <Button variant="secondary" asChild>
                                <Link href={step('logo')}>{t('Back')}</Link>
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

OnboardingCard.layout = {
    title: 'Your loyalty card',
    description: 'What customers earn, and after how many stamps.',
};

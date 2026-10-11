import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { typedDir } from '@/lib/utils';

export type TierField = { stamps: string; reward: string };

/**
 * A progressive card's tiers (B6): the stamps each one needs and its reward,
 * 1 to 10 of them. The top tier is the whole card (5 to 50 stamps).
 */
export default function TierEditor({
    tiers,
    onChange,
    disabled,
    errors,
}: {
    tiers: TierField[];
    onChange: (tiers: TierField[]) => void;
    disabled: boolean;
    errors: Record<string, string | undefined>;
}) {
    const { t } = useTranslation();
    const change = (index: number, tier: Partial<TierField>) =>
        onChange(
            tiers.map((current, at) =>
                at === index ? { ...current, ...tier } : current,
            ),
        );

    return (
        <div className="flex flex-col gap-3">
            <ol className="flex flex-col gap-3">
                {tiers.map((tier, index) => (
                    <li key={index} className="flex flex-col gap-1">
                        <div className="flex items-center gap-2">
                            <Input
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={50}
                                aria-label={t('Stamps for tier :number', {
                                    number: index + 1,
                                })}
                                disabled={disabled}
                                value={tier.stamps}
                                onChange={(event) =>
                                    change(index, {
                                        stamps: event.target.value,
                                    })
                                }
                                className="w-20 shrink-0"
                            />
                            <Input
                                dir={typedDir(tier.reward)}
                                maxLength={120}
                                placeholder={t('Reward')}
                                aria-label={t('Reward for tier :number', {
                                    number: index + 1,
                                })}
                                disabled={disabled}
                                value={tier.reward}
                                onChange={(event) =>
                                    change(index, {
                                        reward: event.target.value,
                                    })
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={t('Remove tier :number', {
                                    number: index + 1,
                                })}
                                disabled={disabled || tiers.length === 1}
                                onClick={() =>
                                    onChange(
                                        tiers.filter((_, at) => at !== index),
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        <InputError
                            message={
                                errors[`tiers.${index}.stamps`] ??
                                errors[`tiers.${index}.reward`]
                            }
                        />
                    </li>
                ))}
            </ol>
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="self-start"
                disabled={disabled || tiers.length >= 10}
                onClick={() => onChange([...tiers, { stamps: '', reward: '' }])}
            >
                <Plus />
                {t('Add a tier')}
            </Button>
            <InputError message={errors.tiers} />
        </div>
    );
}

import { Head } from '@inertiajs/react';
import TapScreen from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import { useTranslation } from '@/hooks/use-translation';

/** Stamped too recently on this card: friendly, not an error (CHW-25). */
export default function TapCooldown({
    card,
    availableAt,
}: {
    card: TapCard | null;
    availableAt: string | null;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Already stamped')} />
            <TapScreen title={t('Already stamped')} card={card}>
                {availableAt ? (
                    <p className="text-muted-foreground">
                        {t('Your next stamp is available at :time.', {
                            time: availableAt,
                        })}
                    </p>
                ) : null}
            </TapScreen>
        </>
    );
}

import { Wallet } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Where Add to Apple Wallet and Add to Google Wallet go (C2, C6): disabled
 * until the passes exist (CHW-38, CHW-40).
 */
export default function WalletPlaceholders() {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-2">
            <Button size="lg" variant="outline" disabled>
                <Wallet aria-hidden="true" />
                {t('Add to Apple Wallet')}
            </Button>
            <Button size="lg" variant="outline" disabled>
                <Wallet aria-hidden="true" />
                {t('Add to Google Wallet')}
            </Button>
            <p className="text-center text-xs text-muted-foreground">
                {t('Wallet passes are coming soon.')}
            </p>
        </div>
    );
}

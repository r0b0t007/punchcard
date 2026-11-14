import { usePage } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { apple } from '@/routes/app/cards/wallet';

/**
 * Apple's "Add to Apple Wallet" badges (design 25a), one per language, from
 * Apple's badge files: resources/images/wallet/add-to-apple-wallet-{en,fr,ar}.svg.
 * Until they are added, an outline button takes the badge's place.
 */
const BADGES = import.meta.glob<string>(
    '../../../images/wallet/add-to-apple-wallet-*.svg',
    { eager: true, query: '?url', import: 'default' },
);

function badgeFor(locale: string): string | null {
    const named = (code: string) =>
        Object.entries(BADGES).find(([path]) =>
            path.endsWith(`add-to-apple-wallet-${code}.svg`),
        )?.[1] ?? null;

    return named(locale) ?? named('en');
}

/** Whether this device keeps passes in Apple Wallet: an iPhone, iPad or Mac. */
function hasAppleWallet(): boolean {
    return /iP(hone|ad|od)|Macintosh/.test(navigator.userAgent);
}

const noSubscription = () => () => {};

/**
 * Add to Wallet on C2 and C6 (CHW-38, design 25a). With the server's Apple
 * certificate set up (the `wallet.apple` prop), an Apple device gets Apple's
 * badge, a plain link to the signed pass that Safari opens in Wallet's add
 * sheet; any other device a line to open the page on an iPhone. Without it,
 * both buttons wait, disabled. Google Wallet comes with CHW-40.
 */
export default function AddToWallet({
    enrollmentId,
}: {
    enrollmentId: number | null;
}) {
    const { t } = useTranslation();
    const { wallet, locale } = usePage<{
        wallet?: { apple: boolean };
        locale: string;
    }>().props;
    const appleDevice = useSyncExternalStore(
        noSubscription,
        hasAppleWallet,
        () => false,
    );
    const ready = wallet?.apple === true && enrollmentId !== null;
    const badge = badgeFor(locale);

    const google = (
        <Button size="lg" variant="outline" disabled className="w-full">
            <Wallet aria-hidden="true" />
            {t('Add to Google Wallet')}
        </Button>
    );

    if (!ready) {
        return (
            <div className="flex flex-col gap-2">
                <Button size="lg" variant="outline" disabled>
                    <Wallet aria-hidden="true" />
                    {t('Add to Apple Wallet')}
                </Button>
                {google}
                <p className="text-center text-xs text-muted-foreground">
                    {t('Wallet passes are coming soon.')}
                </p>
            </div>
        );
    }

    const href = apple(enrollmentId).url;

    return (
        <div className="flex flex-col items-center gap-2">
            {appleDevice ? (
                badge ? (
                    <a
                        href={href}
                        data-test="add-to-apple-wallet"
                        className="rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:ring-1 dark:ring-muted-foreground"
                    >
                        <img
                            src={badge}
                            alt={t('Add to Apple Wallet')}
                            className="h-12 w-auto"
                        />
                    </a>
                ) : (
                    <Button
                        asChild
                        size="lg"
                        variant="outline"
                        className="w-full"
                    >
                        <a href={href} data-test="add-to-apple-wallet">
                            <Wallet aria-hidden="true" />
                            {t('Add to Apple Wallet')}
                        </a>
                    </Button>
                )
            ) : null}
            {google}
            <p className="text-center text-xs text-pretty text-muted-foreground">
                {appleDevice
                    ? t('Google Wallet is coming soon.')
                    : t(
                          'Google Wallet is coming soon. To add this card to Apple Wallet, open this page on your iPhone.',
                      )}
            </p>
        </div>
    );
}

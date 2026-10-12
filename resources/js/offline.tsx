import { DirectionProvider } from '@radix-ui/react-direction';
import { CloudOff } from 'lucide-react';
import { StrictMode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import MyCardsView from '@/components/my-cards-view';
import { Button } from '@/components/ui/button';
import {
    createI18n,
    I18nContext,
    useTranslation,
} from '@/hooks/use-translation';
import { readOfflineCards, updatedAtLabel } from '@/lib/offline-cards';

type Kept = Awaited<ReturnType<typeof readOfflineCards>>;

/**
 * The page the service worker serves when a page of My cards can't load
 * (CHW-35, C5 offline): the cards kept on the phone, in the customer's
 * language, with when they were saved. Nothing in it needs the network;
 * "Try again" reloads the page asked for.
 */
function OfflinePage() {
    const [kept, setKept] = useState<Kept | undefined>(undefined);

    useEffect(() => {
        void readOfflineCards()
            .catch(() => null)
            .then(setKept);
    }, []);

    const locale = kept?.snapshot.locale ?? 'en';
    const dir = kept?.snapshot.dir ?? 'ltr';

    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
    }, [locale, dir]);

    if (kept === undefined) {
        return null;
    }

    const retry = () => window.location.reload();

    return (
        <I18nContext value={createI18n(locale, dir, kept?.strings ?? {})}>
            <DirectionProvider dir={dir}>
                {kept ? (
                    <MyCardsView
                        firstName={kept.snapshot.firstName}
                        rewardsReady={kept.snapshot.rewardsReady}
                        cards={kept.snapshot.cards}
                        offline={{
                            updatedAt: updatedAtLabel(
                                kept.snapshot.generatedAt,
                                locale,
                            ),
                            onRetry: retry,
                        }}
                    />
                ) : (
                    <NothingKept onRetry={retry} />
                )}
            </DirectionProvider>
        </I18nContext>
    );
}

/** Offline with no cards kept on the phone (never opened My cards here, or signed out). */
function NothingKept({ onRetry }: { onRetry: () => void }) {
    const { t } = useTranslation();

    return (
        <main className="mx-auto flex min-h-svh max-w-md flex-col items-center justify-center gap-4 px-4 text-center">
            <CloudOff
                aria-hidden="true"
                className="size-10 text-muted-foreground"
            />
            <h1 className="font-display text-2xl font-bold">
                {t("You're offline")}
            </h1>
            <p className="text-sm text-muted-foreground">
                {t('Your cards show here once you are back online.')}
            </p>
            <Button onClick={onRetry}>{t('Try again')}</Button>
        </main>
    );
}

const root = document.getElementById('offline');

if (root) {
    createRoot(root).render(
        <StrictMode>
            <OfflinePage />
        </StrictMode>,
    );
}

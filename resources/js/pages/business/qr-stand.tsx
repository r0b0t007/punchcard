import { Head, Link } from '@inertiajs/react';
import BrandMonogram from '@/components/loyalty-card/brand-monogram';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';

type Props = {
    businessName: string;
    logoUrl: string | null;
    joinUrl: string;
    /** The QR code as an SVG data URL, made on the server from the app's own link (JoinQrCode). */
    qrCode: string;
};

/**
 * The printable QR stand (CHW-31): the business's name and logo and a QR
 * code to its join page, for a customer without NFC. The buttons don't print.
 */
export default function QrStand({
    businessName,
    logoUrl,
    joinUrl,
    qrCode,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('QR stand')} />
            <main className="mx-auto flex min-h-dvh w-full max-w-md flex-col items-center gap-8 bg-background p-6 text-foreground print:max-w-none print:p-0">
                <div className="flex w-full gap-3 print:hidden">
                    <Button variant="secondary" asChild>
                        <Link href={dashboard()}>{t('Back')}</Link>
                    </Button>
                    <Button className="flex-1" onClick={() => window.print()}>
                        {t('Print')}
                    </Button>
                </div>

                <section className="flex w-full flex-col items-center gap-6 rounded-2xl border p-8 text-center print:border-0">
                    {logoUrl ? (
                        <img
                            src={logoUrl}
                            alt=""
                            className="size-20 object-contain"
                        />
                    ) : (
                        <BrandMonogram
                            name={businessName}
                            brandColor={null}
                            className="size-20 text-4xl"
                        />
                    )}
                    <h1 className="font-display text-3xl font-bold text-balance">
                        {businessName}
                    </h1>
                    <img
                        src={qrCode}
                        alt={t('QR code to join the loyalty card')}
                        className="size-64"
                    />
                    <p className="text-xl font-semibold text-balance">
                        {t('Scan to get your loyalty card')}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Then tap the stamper at the counter for your stamps.',
                        )}
                    </p>
                    <p
                        className="text-xs break-all text-muted-foreground"
                        dir="ltr"
                    >
                        {joinUrl}
                    </p>
                </section>
            </main>
        </>
    );
}

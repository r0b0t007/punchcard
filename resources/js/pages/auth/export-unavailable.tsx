import { Head, Link } from '@inertiajs/react';
import { ClockAlert, ShieldX } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/customers';

/**
 * An export's link that no longer opens (CHW-49, design 21): a day passed
 * (or the file is gone), so ask again from the Customers page; or it isn't
 * this account's (another account, or an owner no longer).
 */
export default function ExportUnavailable({
    reason,
}: {
    reason: 'expired' | 'not-yours';
}) {
    const { t } = useTranslation();
    const expired = reason === 'expired';
    const title = expired
        ? t('This export has expired')
        : t("This export isn't available to you");
    const Icon = expired ? ClockAlert : ShieldX;

    return (
        <>
            <Head title={title} />
            <div
                className="flex flex-col items-center gap-4 text-center"
                data-test={`export-${reason}`}
            >
                <Icon className="size-10" aria-hidden />
                <h1 className="font-display text-form-title font-bold text-balance">
                    {title}
                </h1>
                <p className="text-sm text-pretty text-muted-foreground">
                    {expired
                        ? t(
                              'Export links work for 24 hours. Ask for a new one from the Customers page.',
                          )
                        : t(
                              'Only the owner who asked for it can download it, signed in to their own account.',
                          )}
                </p>
            </div>
            <Button size="lg" asChild className="w-full">
                <Link href={index()}>{t('Go to Customers')}</Link>
            </Button>
        </>
    );
}

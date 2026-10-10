import { Link } from '@inertiajs/react';
import { CheckCircle2, Circle } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import { qrStand } from '@/routes/business';

export type Checklist = {
    qrStand: boolean;
    stamperPlaced: boolean;
    staffInvited: boolean;
};

/**
 * What is left before the first customers (CHW-31, B2): each item ticks
 * itself from what happened (DescribeChecklist). Hidden once all are done.
 */
export default function SetupChecklist({
    checklist,
}: {
    checklist: Checklist;
}) {
    const { t } = useTranslation();

    if (
        checklist.qrStand &&
        checklist.stamperPlaced &&
        checklist.staffInvited
    ) {
        return null;
    }

    return (
        <section className="rounded-xl border p-6">
            <h2 className="text-lg font-semibold">
                {t('Before your first customers')}
            </h2>
            <ul className="mt-4 flex flex-col gap-4">
                <Item done={checklist.qrStand}>
                    <Link
                        href={qrStand()}
                        className="font-medium underline underline-offset-4"
                    >
                        {t('Print your QR stand')}
                    </Link>
                    <p className="text-sm text-muted-foreground">
                        {t('Customers without NFC scan it to get your card.')}
                    </p>
                </Item>
                <Item done={checklist.stamperPlaced}>
                    <p className="font-medium">{t('Place your stamper')}</p>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Put it by the till. It is ticked with its first tap.',
                        )}
                    </p>
                </Item>
                <Item done={checklist.staffInvited}>
                    <p className="font-medium">{t('Invite your staff')}</p>
                    <p className="text-sm text-muted-foreground">
                        {t('Staff invitations are coming soon.')}
                    </p>
                </Item>
            </ul>
        </section>
    );
}

function Item({ done, children }: { done: boolean; children: ReactNode }) {
    const { t } = useTranslation();

    return (
        <li className="flex gap-3">
            {done ? (
                <CheckCircle2
                    className="mt-0.5 size-5 shrink-0 text-primary"
                    aria-label={t('Done')}
                />
            ) : (
                <Circle
                    className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    aria-label={t('To do')}
                />
            )}
            <div className="flex flex-col gap-1">{children}</div>
        </li>
    );
}

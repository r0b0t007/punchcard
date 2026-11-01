import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { CustomerStatus } from '@/lib/customers';

/** A customer's status on B4 and B5 (CHW-44): active, going quiet or lost. */
export default function StatusChip({ status }: { status: CustomerStatus }) {
    const { t } = useTranslation();

    return (
        <span
            className={cn(
                'inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap',
                status === 'active' && 'bg-success/15 text-success',
                status === 'quiet' && 'bg-warning/15 text-warning',
                status === 'lost' && 'bg-muted text-muted-foreground',
            )}
            data-test="customer-status"
        >
            <span
                aria-hidden="true"
                className="size-1.5 rounded-full bg-current"
            />
            {status === 'active'
                ? t('Active')
                : status === 'quiet'
                  ? t('Going quiet')
                  : t('Lost')}
        </span>
    );
}

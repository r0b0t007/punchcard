import type { TranslateFn } from '@/hooks/use-translation';

/** A customer's status on B4 and B5 (CHW-44, CustomerStatus). */
export type CustomerStatus = 'active' | 'quiet' | 'lost';

/** "Today", "Yesterday", "16 days ago": calendar days in the business's time. */
export function lastVisit(t: TranslateFn, days: number): string {
    if (days === 0) {
        return t('Today');
    }

    if (days === 1) {
        return t('Yesterday');
    }

    return t(':count days ago', { count: days });
}

/** "ST" for "Salma T.": the first letter of each word, two at most. */
export function initials(name: string): string {
    return name
        .split(/\s+/u)
        .filter((word) => word !== '')
        .map((word) => Array.from(word)[0] ?? '')
        .join('')
        .slice(0, 2);
}

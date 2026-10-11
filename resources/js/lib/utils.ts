import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

/**
 * dir for a field people type in, either script: auto once it holds text,
 * else the page's own direction, so an empty field and its placeholder
 * follow an Arabic page (auto reads an empty field as left to right).
 */
export function typedDir(value: string): 'auto' | undefined {
    return value === '' ? undefined : 'auto';
}

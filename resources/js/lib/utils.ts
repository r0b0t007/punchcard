import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

/**
 * tailwind-merge, told about the theme's own radii and text sizes
 * (resources/css/app.css), so `rounded-md` replaces `rounded-button` and
 * `text-title` isn't mistaken for a colour.
 */
const twMerge = extendTailwindMerge({
    extend: {
        theme: {
            radius: ['card', 'button', 'nav', 'sheet', 'panel'],
            text: ['title', 'heading', 'md', 'giant'],
        },
    },
});

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

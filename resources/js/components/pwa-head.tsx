import { useEffect } from 'react';
import type { Pwa } from '@/types/pwa';

/** The <head> tags of an app, in app.blade.php's order (UsePwa::describe). */
function tagsOf(pwa: NonNullable<Pwa>): HTMLElement[] {
    const link = document.createElement('link');
    link.rel = 'manifest';
    link.href = pwa.manifest;

    const metas = Object.entries(pwa.meta).map(([name, content]) => {
        const meta = document.createElement('meta');
        meta.name = name;
        meta.content = content;

        return meta;
    });

    return [link, ...metas].map((tag) => {
        tag.setAttribute('data-pwa', '');

        return tag;
    });
}

/**
 * Keeps the page's app in the <head> on every visit (CHW-35): the server
 * writes the manifest link and the app metas on a full load
 * (app.blade.php, marked data-pwa), but an Inertia visit keeps the head of
 * the page it came from. Each visit replaces the whole set, so a page of
 * no app has none and never installs as one. Both come from the `pwa` prop.
 */
export default function PwaHead({ pwa }: { pwa: Pwa }) {
    useEffect(() => {
        const current = document.head.querySelectorAll('[data-pwa]');
        const wanted = pwa ? tagsOf(pwa) : [];

        if (
            current.length === wanted.length &&
            wanted.every((tag, i) => tag.isEqualNode(current[i]))
        ) {
            return;
        }

        current.forEach((tag) => tag.remove());
        document.head.append(...wanted);
    }, [pwa]);

    return null;
}

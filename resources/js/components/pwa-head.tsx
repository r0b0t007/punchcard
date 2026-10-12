import { useEffect } from 'react';

type Pwa = { app: 'customer' | 'business'; title: string } | null;

/** The <head> tags of an installable app, as app.blade.php writes them. */
function tagsOf({ app, title }: NonNullable<Pwa>): HTMLElement[] {
    const link = document.createElement('link');
    link.rel = 'manifest';
    link.href = `/manifest-${app}.webmanifest`;

    const metas = Object.entries({
        'theme-color': '#3B2A20',
        'mobile-web-app-capable': 'yes',
        'apple-mobile-web-app-capable': 'yes',
        'apple-mobile-web-app-status-bar-style': 'default',
        'apple-mobile-web-app-title': title,
    }).map(([name, content]) => {
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
 * no app has none and never installs as one.
 */
export default function PwaHead({ pwa }: { pwa: Pwa }) {
    const app = pwa?.app ?? null;
    const title = pwa?.title ?? null;

    useEffect(() => {
        const current = document.head.querySelectorAll('[data-pwa]');
        const wanted = app && title ? tagsOf({ app, title }) : [];

        if (
            current.length === wanted.length &&
            wanted.every((tag, i) => tag.isEqualNode(current[i]))
        ) {
            return;
        }

        current.forEach((tag) => tag.remove());
        document.head.append(...wanted);
    }, [app, title]);

    return null;
}

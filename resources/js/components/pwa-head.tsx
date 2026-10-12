import { useEffect } from 'react';

const TITLES = { customer: 'punchcard', business: 'punchcard Pro' } as const;

/**
 * Keeps the page's app in the <head> on every visit (CHW-35): the server
 * writes the manifest link on a full load (app.blade.php), but an Inertia
 * visit (signing in, a link) keeps the head of the page it came from. A
 * page of no app drops the link, so it never installs as one.
 */
export default function PwaHead({
    app,
}: {
    app: 'customer' | 'business' | null;
}) {
    useEffect(() => {
        let link = document.head.querySelector<HTMLLinkElement>(
            'link[rel="manifest"]',
        );

        if (app === null) {
            link?.remove();

            return;
        }

        if (!link) {
            link = document.createElement('link');
            link.rel = 'manifest';
            document.head.append(link);
        }

        const href = `/manifest-${app}.webmanifest`;

        if (link.getAttribute('href') !== href) {
            link.setAttribute('href', href);
        }

        document.head
            .querySelector('meta[name="apple-mobile-web-app-title"]')
            ?.setAttribute('content', TITLES[app]);
    }, [app]);

    return null;
}

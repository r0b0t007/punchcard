import { router } from '@inertiajs/react';
import { DirectionProvider } from '@radix-ui/react-direction';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import type { TextDirection } from '@/types';

type LocalePage = { props: { locale: string; dir: TextDirection } };

/**
 * Keeps the document and Radix in the active text direction.
 *
 * The server renders <html lang dir>. On the client, `success` covers every
 * completed visit, including the language switcher's same-URL redirect (a
 * history replace, so no `navigate`); `navigate` covers back/forward, which
 * makes no request. Radix primitives (menus, toggle groups, navigation menu)
 * read the direction from DirectionProvider, not from the document.
 */
export default function AppDirectionProvider({
    children,
}: {
    children: ReactNode;
}) {
    const [dir, setDir] = useState<TextDirection>(() =>
        document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr',
    );

    useEffect(() => {
        const sync = ({ props }: LocalePage) => {
            document.documentElement.lang = props.locale;
            document.documentElement.dir = props.dir;
            setDir(props.dir);
        };

        const stopSuccess = router.on('success', (event) =>
            sync(event.detail.page),
        );
        const stopNavigate = router.on('navigate', (event) =>
            sync(event.detail.page),
        );

        return () => {
            stopSuccess();
            stopNavigate();
        };
    }, []);

    return <DirectionProvider dir={dir}>{children}</DirectionProvider>;
}

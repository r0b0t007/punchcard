import { createInertiaApp, router } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { TextDirection } from '@/types';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// The server sets <html lang dir> on the first load; keep them in sync when the
// locale changes client-side. `success` covers every completed visit, including
// the language switcher's same-URL redirect (a history replace, so no
// `navigate`); `navigate` covers back/forward, which makes no request.
const syncDocumentLocale = (page: {
    props: { locale: string; dir: TextDirection };
}) => {
    document.documentElement.lang = page.props.locale;
    document.documentElement.dir = page.props.dir;
};
router.on('success', (event) => syncDocumentLocale(event.detail.page));
router.on('navigate', (event) => syncDocumentLocale(event.detail.page));

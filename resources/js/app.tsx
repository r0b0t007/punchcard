import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import I18nLayout from '@/layouts/i18n-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // I18nLayout comes first on every page: it provides translations and text
    // direction from the page props.
    layout: (name) => {
        switch (true) {
            case name === 'welcome' ||
                name.startsWith('dev/') ||
                name.startsWith('tap/') ||
                // The customer's reward screens: full screen, like the tap screens (CHW-26).
                name.startsWith('rewards/'):
                return I18nLayout;
            case name.startsWith('auth/'):
                return [I18nLayout, AuthLayout];
            case name.startsWith('settings/'):
                return [I18nLayout, AppLayout, SettingsLayout];
            default:
                return [I18nLayout, AppLayout];
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

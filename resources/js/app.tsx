// First: the browser's install prompt can come before anything else is ready (CHW-35, C13).
import '@/lib/install-prompt';
import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import CustomerLayout from '@/layouts/customer-layout';
import I18nLayout from '@/layouts/i18n-layout';
import OnboardingLayout from '@/layouts/onboarding-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // I18nLayout comes first on every page: it provides translations and text
    // direction from the page props.
    layout: (name, page) => {
        switch (true) {
            // "My QR" (CHW-28, C10): full screen, the code alone, before the app's other pages.
            case name === 'app/qr':
                return I18nLayout;
            // The customer's home and rewards (CHW-27): full screen with the bottom tab bar.
            case name.startsWith('app/') || name === 'rewards/index':
                return [I18nLayout, CustomerLayout];
            case name === 'welcome' ||
                name.startsWith('dev/') ||
                name.startsWith('tap/') ||
                // The redeem screen: full screen, like the tap screens (CHW-26).
                name.startsWith('rewards/') ||
                // The join page and the printable QR stand (CHW-31): full screen.
                name.startsWith('join/') ||
                name === 'business/qr-stand' ||
                // Counter mode (CHW-29, S1) and scanning from it (CHW-30): full screen, for the counter's tablet or phone.
                name === 'business/counter' ||
                name === 'business/scan' ||
                name === 'business/scan-result':
                return I18nLayout;
            case name.startsWith('auth/'):
                return [I18nLayout, AuthLayout];
            // The business onboarding wizard (CHW-31): full screen, its own steps.
            case name.startsWith('onboarding/'):
                return [I18nLayout, OnboardingLayout];
            // A customer's settings sit in their own layout, with the tab bar (CHW-27).
            case name.startsWith('settings/'):
                return page.props.customer
                    ? [I18nLayout, CustomerLayout, SettingsLayout]
                    : [I18nLayout, AppLayout, SettingsLayout];
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

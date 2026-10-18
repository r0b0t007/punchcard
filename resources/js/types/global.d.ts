import type { Auth } from '@/types/auth';
import type { LocaleOption, TextDirection } from '@/types/i18n';
import type { Pwa } from '@/types/pwa';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            /** The sidebar's pages the user may open: cards (CHW-32), staff (CHW-146), counter mode (CHW-29), stampers (CHW-36). */
            nav: {
                cards: boolean;
                staff: boolean;
                counter: boolean;
                stampers: boolean;
            };
            /** The installable app the page belongs to (UsePwa, CHW-35). */
            pwa: Pwa;
            /** On settings pages: the user is a customer, so they use the customer layout (CHW-27). */
            customer: boolean;
            sidebarOpen: boolean;
            locale: string;
            dir: TextDirection;
            locales: LocaleOption[];
            translations: Record<string, string>;
            [key: string]: unknown;
        };
    }
}

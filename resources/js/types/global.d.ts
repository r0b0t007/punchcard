import type { Auth } from '@/types/auth';
import type { LocaleOption, TextDirection } from '@/types/i18n';

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
            sidebarOpen: boolean;
            locale: string;
            dir: TextDirection;
            locales: LocaleOption[];
            translations: Record<string, string>;
            [key: string]: unknown;
        };
    }
}

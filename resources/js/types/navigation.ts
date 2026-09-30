import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    /**
     * English translation key, translated where it is rendered (t(title)).
     * Titles that come from data (a business or card name) must not go through
     * t(): add a raw-title option when the first one appears.
     */
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    /** English translation key, translated where it is rendered; see BreadcrumbItem. */
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

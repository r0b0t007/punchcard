import type { Page, VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/react';

/** As EnsureTenantUnchanged reads it. */
export const TENANT_HEADER = 'X-Punchcard-Tenant';

/** The app's tabs tell each other where they work (StaleTenantNotice). */
export const TENANT_CHANNEL = 'punchcard-tenant';

/** The tenant a business page is rendered for (HandleInertiaRequests, CHW-147). */
export type ShownTenant = {
    /** As SetTenant keeps it ("business:7", "org:5"), or "none". */
    choice: string;
    name: string | null;
    /** When the server rendered the page (ms): the latest render is where the session works. */
    at: number;
};

let shown: string | null = null;

/** The page on screen now names its tenant. */
export function showTenant(tenant: ShownTenant | null | undefined): void {
    shown = tenant?.choice ?? null;
}

/**
 * Follows the page on screen: every page Inertia puts up, the first one
 * included (visits, history). Called once, before the app starts.
 */
export function followTenant(): void {
    router.on('navigate', (event) =>
        showTenant(
            (event.detail.page as Page<{ tenant?: ShownTenant | null }>).props
                .tenant,
        ),
    );
}

/**
 * Every visit from a business page names the tenant it was rendered for
 * (createInertiaApp's defaults.visitOptions), so a tab left on another
 * business saves nothing (CHW-147, design 10f). Other pages name none.
 */
export function withTenantHeader(
    _href: string,
    options: VisitOptions,
): VisitOptions {
    if (shown === null) {
        return {};
    }

    return { headers: { ...options.headers, [TENANT_HEADER]: shown } };
}

/**
 * What a tab does on hearing where another tab works: nothing for the same
 * tenant; dim itself if the other page is newer (the session switched since
 * this one was rendered); otherwise tell it, so the older page, maybe one
 * put back from history, is the one that dims.
 */
export function hearTenant(
    own: ShownTenant,
    theirs: ShownTenant,
): 'same' | 'stale' | 'tell' {
    if (theirs.choice === own.choice) {
        return 'same';
    }

    return theirs.at > own.at ? 'stale' : 'tell';
}

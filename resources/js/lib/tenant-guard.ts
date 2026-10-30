import type { VisitOptions } from '@inertiajs/core';

/** As EnsureTenantUnchanged reads it. */
export const TENANT_HEADER = 'X-Punchcard-Tenant';

/** The app's tabs tell each other where they work (StaleTenantNotice). */
export const TENANT_CHANNEL = 'punchcard-tenant';

/** The tenant a business page is rendered for (HandleInertiaRequests, CHW-147). */
export type ShownTenant = {
    /** As SetTenant keeps it ("business:7", "org:5"), or "none". */
    choice: string;
    name: string | null;
};

let shown: string | null = null;

/** The page on screen now, kept current by StaleTenantNotice. */
export function showTenant(tenant: ShownTenant | null): void {
    shown = tenant?.choice ?? null;
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

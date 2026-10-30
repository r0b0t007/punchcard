import { afterEach, describe, expect, it } from 'vite-plus/test';
import {
    showTenant,
    TENANT_HEADER,
    withTenantHeader,
} from '@/lib/tenant-guard';

describe('withTenantHeader', () => {
    afterEach(() => showTenant(null));

    it('names the tenant of the page on screen, keeping the visit’s own headers', () => {
        showTenant({ choice: 'business:7', name: 'Café Hafa' });

        expect(
            withTenantHeader('/business/cards', {
                headers: { 'X-Other': '1' },
            }),
        ).toEqual({
            headers: { 'X-Other': '1', [TENANT_HEADER]: 'business:7' },
        });
    });

    it('follows the page: a switch names the new one', () => {
        showTenant({ choice: 'business:7', name: 'Café Hafa' });
        showTenant({ choice: 'org:5', name: 'Pâtisserie Amal' });

        expect(withTenantHeader('/business', {}).headers).toEqual({
            [TENANT_HEADER]: 'org:5',
        });
    });

    it('names none from a page outside the business app', () => {
        showTenant(null);

        expect(withTenantHeader('/settings/profile', {})).toEqual({});
    });
});

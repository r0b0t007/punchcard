import { afterEach, describe, expect, it } from 'vite-plus/test';
import {
    hearTenant,
    showTenant,
    TENANT_HEADER,
    withTenantHeader,
} from '@/lib/tenant-guard';

const hafa = { choice: 'business:7', name: 'Café Hafa', at: 1000 };
const amal = { choice: 'org:5', name: 'Pâtisserie Amal', at: 2000 };

describe('withTenantHeader', () => {
    afterEach(() => showTenant(null));

    it('names the tenant of the page on screen, keeping the visit’s own headers', () => {
        showTenant(hafa);

        expect(
            withTenantHeader('/business/cards', {
                headers: { 'X-Other': '1' },
            }),
        ).toEqual({
            headers: { 'X-Other': '1', [TENANT_HEADER]: 'business:7' },
        });
    });

    it('follows the page: a switch names the new one', () => {
        showTenant(hafa);
        showTenant(amal);

        expect(withTenantHeader('/business', {}).headers).toEqual({
            [TENANT_HEADER]: 'org:5',
        });
    });

    it('names none from a page outside the business app', () => {
        showTenant(null);

        expect(withTenantHeader('/settings/profile', {})).toEqual({});
    });
});

describe('hearTenant', () => {
    it('changes nothing for the same tenant, whenever rendered', () => {
        expect(hearTenant(hafa, { ...hafa, at: 5000 })).toBe('same');
    });

    it('dims the page rendered before a switch elsewhere', () => {
        expect(hearTenant(hafa, amal)).toBe('stale');
    });

    it('tells an older page, maybe one put back from history, instead of dimming', () => {
        expect(hearTenant(amal, hafa)).toBe('tell');
    });
});

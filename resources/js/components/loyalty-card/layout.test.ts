import { describe, expect, it } from 'vite-plus/test';
import {
    clampStamps,
    columnsFor,
    gridColumnsClass,
    rotationClass,
} from '@/components/loyalty-card/layout';

describe('columnsFor', () => {
    it('balances rows for 5 to 50 stamps', () => {
        expect([5, 6, 8, 10, 12, 15, 20, 33, 50].map(columnsFor)).toEqual([
            5, 6, 4, 5, 6, 8, 10, 9, 10,
        ]);
    });

    it('never exceeds 10 columns and has a static class for each', () => {
        for (let count = 5; count <= 50; count++) {
            expect(columnsFor(count)).toBeLessThanOrEqual(10);
            expect(gridColumnsClass(count)).toMatch(/^grid-cols-\d+$/);
        }
    });
});

describe('clampStamps', () => {
    it('keeps required in 5..50 and collected in 0..required', () => {
        expect(clampStamps(3, 9)).toEqual({ required: 5, collected: 5 });
        expect(clampStamps(80, -2)).toEqual({ required: 50, collected: 0 });
        expect(clampStamps(10, 4)).toEqual({ required: 10, collected: 4 });
    });
});

describe('rotationClass', () => {
    it('gives each position a fixed slight rotation', () => {
        expect(rotationClass(0)).toBe(rotationClass(0));
        expect(
            new Set(Array.from({ length: 10 }, (_, i) => rotationClass(i)))
                .size,
        ).toBeGreaterThan(4);
    });
});

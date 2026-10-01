import { describe, expect, it } from 'vite-plus/test';
import {
    clampStamps,
    columnsFor,
    gridColumnsClass,
    progressFor,
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

describe('progressFor', () => {
    it('keeps the real numbers for text and the grid for display', () => {
        expect(progressFor(10, 4)).toEqual({
            required: 10,
            collected: 4,
            remaining: 6,
            slots: 10,
            filled: 4,
        });
    });

    it('never claims a reward for out-of-range data', () => {
        const progress = progressFor(60, 52);
        expect(progress.remaining).toBe(8);
        expect(progress.slots).toBe(50);
        expect(progress.filled).toBe(43); // proportional, not a full grid
    });

    it('maps small and negative values sensibly', () => {
        expect(progressFor(3, 3)).toMatchObject({
            remaining: 0,
            slots: 5,
            filled: 5,
        });
        expect(progressFor(3, 1)).toMatchObject({
            remaining: 2,
            slots: 5,
            filled: 2,
        });
        expect(progressFor(80, -2)).toMatchObject({
            collected: 0,
            remaining: 80,
            slots: 50,
            filled: 0,
        });
    });
});

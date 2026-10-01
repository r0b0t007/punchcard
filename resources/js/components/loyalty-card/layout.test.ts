import { describe, expect, it } from 'vite-plus/test';
import {
    clampSlots,
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

describe('clampSlots', () => {
    it('keeps the grid between 5 and 50 slots', () => {
        expect([3, 5, 12, 50, 80].map(clampSlots)).toEqual([5, 5, 12, 50, 50]);
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

    it('never shows a full grid while stamps are still owed', () => {
        expect(progressFor(60, 52)).toMatchObject({
            remaining: 8,
            slots: 50,
            filled: 43,
        });
        expect(progressFor(100, 99)).toMatchObject({
            remaining: 1,
            slots: 50,
            filled: 49,
        });
    });

    it('shows at least one stamp once one is collected', () => {
        expect(progressFor(200, 1)).toMatchObject({
            remaining: 199,
            filled: 1,
        });
    });

    it('maps small, negative and missing values sensibly', () => {
        expect(progressFor(3, 3)).toMatchObject({
            remaining: 0,
            slots: 5,
            filled: 5,
        });
        expect(progressFor(3, 1)).toMatchObject({
            remaining: 2,
            slots: 5,
            filled: 1,
        });
        expect(progressFor(80, -2)).toMatchObject({
            collected: 0,
            remaining: 80,
            slots: 50,
            filled: 0,
        });
        expect(progressFor(Number.NaN, Number.NaN)).toEqual({
            required: 5,
            collected: 0,
            remaining: 5,
            slots: 5,
            filled: 0,
        });
    });
});

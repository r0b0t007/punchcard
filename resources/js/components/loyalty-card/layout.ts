/** Layout rules for the LoyaltyCard stamp grid (5 to 50 stamps). */

export const MIN_STAMPS = 5;
export const MAX_STAMPS = 50;

// Static class names so Tailwind generates them.
const GRID_COLUMNS: Record<number, string> = {
    1: 'grid-cols-1',
    2: 'grid-cols-2',
    3: 'grid-cols-3',
    4: 'grid-cols-4',
    5: 'grid-cols-5',
    6: 'grid-cols-6',
    7: 'grid-cols-7',
    8: 'grid-cols-8',
    9: 'grid-cols-9',
    10: 'grid-cols-10',
};

// Filled stamps sit slightly askew, like a rubber stamp; fixed per position.
const ROTATIONS = [
    '-rotate-6',
    'rotate-3',
    '-rotate-2',
    'rotate-6',
    '-rotate-3',
    'rotate-2',
    '-rotate-1',
    'rotate-1',
];

/** The grid always draws 5 to 50 slots. */
export function clampSlots(count: number): number {
    return Math.min(MAX_STAMPS, Math.max(MIN_STAMPS, Math.round(count)));
}

/**
 * Balanced rows: up to 6 per row for small cards (12 or fewer), up to 10 above,
 * with the stamps spread evenly over the rows (8 -> 4+4, 15 -> 8+7).
 */
export function columnsFor(count: number): number {
    const maxColumns = count <= 12 ? 6 : 10;
    const rows = Math.ceil(count / maxColumns);

    return Math.ceil(count / rows);
}

export function gridColumnsClass(count: number): string {
    return GRID_COLUMNS[columnsFor(count)];
}

export function rotationClass(index: number): string {
    return ROTATIONS[(index * 5) % ROTATIONS.length];
}

export type Progress = {
    /** Real numbers, for the count, the progress text and the accessible name. */
    required: number;
    collected: number;
    remaining: number;
    /** What the grid draws: 5 to 50 slots, filled in proportion when they differ from required. */
    slots: number;
    filled: number;
};

/**
 * Card progress from the server's numbers. The grid is capped to 5..50 slots,
 * but completion always comes from the real values: out-of-range data never
 * shows "Reward ready" or a full grid while stamps are owed, and shows at
 * least one filled slot once a stamp is collected. Missing (non-finite)
 * values fall back to an empty 5-stamp card.
 */
export function progressFor(
    stampsRequired: number,
    stampsCollected: number,
): Progress {
    const required = Number.isFinite(stampsRequired)
        ? Math.max(1, Math.round(stampsRequired))
        : MIN_STAMPS;
    const collected = Number.isFinite(stampsCollected)
        ? Math.min(required, Math.max(0, Math.round(stampsCollected)))
        : 0;
    const remaining = required - collected;
    const slots = clampSlots(required);

    let filled = collected;

    if (slots !== required) {
        filled = Math.floor((collected / required) * slots);

        if (remaining > 0) {
            filled = Math.min(filled, slots - 1);
        }

        if (collected > 0) {
            filled = Math.max(filled, 1);
        }

        if (remaining === 0) {
            filled = slots;
        }
    }

    return { required, collected, remaining, slots, filled };
}

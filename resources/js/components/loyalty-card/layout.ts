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

export function clampStamps(
    required: number,
    collected: number,
): { required: number; collected: number } {
    const total = Math.min(
        MAX_STAMPS,
        Math.max(MIN_STAMPS, Math.round(required)),
    );

    return {
        required: total,
        collected: Math.min(total, Math.max(0, Math.round(collected))),
    };
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
 * but completion always comes from the real values, so out-of-range data
 * never shows "Reward ready" or a full grid by accident.
 */
export function progressFor(
    stampsRequired: number,
    stampsCollected: number,
): Progress {
    const required = Math.max(1, Math.round(stampsRequired));
    const collected = Math.min(
        required,
        Math.max(0, Math.round(stampsCollected)),
    );
    const slots = clampStamps(required, 0).required;
    const filled =
        slots === required
            ? collected
            : Math.min(slots, Math.round((collected / required) * slots));

    return {
        required,
        collected,
        remaining: required - collected,
        slots,
        filled,
    };
}

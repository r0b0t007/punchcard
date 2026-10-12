import { CARDS_CACHE } from '@/sw-routes';
import type { TextDirection } from '@/types/i18n';
import type { MyCard } from '@/types/my-cards';

/**
 * The customer's cards kept on the phone for offline use (CHW-35, C5): My
 * cards saves them on every visit, the offline page shows them with when
 * they were saved. A sign-out drops them (forgetOfflineCards, and the
 * server's Clear-Site-Data), so a shared phone keeps nobody's cards.
 */
export type OfflineSnapshot = {
    userId: number;
    firstName: string;
    rewardsReady: number;
    cards: MyCard[];
    /** When the cards were read, ISO 8601. */
    generatedAt: string;
    locale: string;
    dir: TextDirection;
    translations: Record<string, string>;
};

const DATABASE = 'punchcard-offline';
const STORE = 'snapshots';
const KEY = 'my-cards';

/** My cards' page props as the snapshot kept for offline use. */
export function snapshotOf(
    page: {
        firstName: string;
        rewardsReady: number;
        cards: MyCard[];
        userId: number;
        locale: string;
        dir: TextDirection;
        translations: Record<string, string>;
    },
    now: Date,
): OfflineSnapshot {
    return {
        userId: page.userId,
        firstName: page.firstName,
        rewardsReady: page.rewardsReady,
        cards: page.cards,
        generatedAt: now.toISOString(),
        locale: page.locale,
        dir: page.dir,
        translations: page.translations,
    };
}

function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DATABASE, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(STORE);
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function inStore<T>(
    mode: IDBTransactionMode,
    run: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
    return open().then((database) =>
        new Promise<T>((resolve, reject) => {
            const request = run(
                database.transaction(STORE, mode).objectStore(STORE),
            );
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        }).finally(() => database.close()),
    );
}

export function saveSnapshot(snapshot: OfflineSnapshot): Promise<void> {
    return inStore('readwrite', (store) => store.put(snapshot, KEY)).then(
        () => undefined,
    );
}

export function readSnapshot(): Promise<OfflineSnapshot | undefined> {
    return inStore<OfflineSnapshot | undefined>('readonly', (store) =>
        store.get(KEY),
    );
}

/**
 * Drops the cards kept on the phone: the snapshot and the service
 * worker's copy of /api/me/cards. Never fails a sign-out.
 */
export async function forgetOfflineCards(): Promise<void> {
    await Promise.allSettled([
        new Promise<void>((resolve) => {
            const request = indexedDB.deleteDatabase(DATABASE);
            request.onsuccess =
                request.onerror =
                request.onblocked =
                    () => resolve();
        }),
        'caches' in window ? caches.delete(CARDS_CACHE) : Promise.resolve(),
    ]);
}

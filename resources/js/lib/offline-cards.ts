import type { TextDirection } from '@/types/i18n';
import type { MyCard } from '@/types/my-cards';

/**
 * The customer's cards kept on the phone for offline use (CHW-35, C5): My
 * cards saves them on every visit, the offline page shows them with when
 * they were saved. They are only ever the signed-in customer's: a
 * sign-out drops them, and so does any page seen by someone else or by a
 * guest (keepOnlyCardsOf), however the session ended.
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
};

/** The words of one language, kept apart from the cards: rewritten only when they change. */
export type OfflineTranslations = {
    locale: string;
    /** The app's version (Inertia's asset version) they came with. */
    version: string;
    strings: Record<string, string>;
};

const DATABASE = 'punchcard-offline';
const STORE = 'snapshots';
const CARDS = 'my-cards';
const TRANSLATIONS = 'translations';

/** Whose cards are kept, readable without opening IndexedDB on every page. */
const OWNER = 'punchcard-offline-owner';

/** My cards' page props as the snapshot kept for offline use. */
export function snapshotOf(
    page: Omit<OfflineSnapshot, 'generatedAt'>,
    now: Date,
): OfflineSnapshot {
    return { ...page, generatedAt: now.toISOString() };
}

/** Whether the cards kept belong to someone other than who is signed in now (or a guest). */
export function keptForSomeoneElse(
    owner: string | null,
    userId: number | null,
): boolean {
    return owner !== null && owner !== String(userId);
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

function owner(): string | null {
    try {
        return localStorage.getItem(OWNER);
    } catch {
        return null;
    }
}

export async function saveSnapshot(snapshot: OfflineSnapshot): Promise<void> {
    await inStore('readwrite', (store) => store.put(snapshot, CARDS));

    try {
        localStorage.setItem(OWNER, String(snapshot.userId));
    } catch {
        // Without the marker the guard can't see the cards: drop them rather than keep them unguarded.
        await forgetOfflineCards();
    }
}

/** Saves the words of the page's language, unless the same ones are already kept. */
export async function saveTranslations(
    translations: OfflineTranslations,
): Promise<void> {
    const kept = await inStore<OfflineTranslations | undefined>(
        'readonly',
        (store) => store.get(TRANSLATIONS),
    );

    if (
        kept?.locale === translations.locale &&
        kept.version === translations.version
    ) {
        return;
    }

    await inStore('readwrite', (store) =>
        store.put(translations, TRANSLATIONS),
    );
}

/** Drops the cards kept on the phone, and whose they were. Never fails a sign-out. */
export async function forgetOfflineCards(): Promise<void> {
    try {
        localStorage.removeItem(OWNER);
    } catch {
        // Storage unavailable: nothing was kept with it either.
    }

    await new Promise<void>((resolve) => {
        const request = indexedDB.deleteDatabase(DATABASE);
        request.onsuccess =
            request.onerror =
            request.onblocked =
                () => resolve();
    });
}

/**
 * Keeps the cards on the phone only for who is signed in now: any page
 * seen by someone else, or by a guest (a session that expired, a password
 * changed on another device), drops them.
 */
export async function keepOnlyCardsOf(userId: number | null): Promise<void> {
    if (keptForSomeoneElse(owner(), userId)) {
        await forgetOfflineCards();
    }
}

/** Whether the cards kept are the last signed-in customer's (a copy without its owner is never shown). */
export function isOwnedBy(
    snapshot: OfflineSnapshot,
    owner: string | null,
): boolean {
    return owner !== null && !keptForSomeoneElse(owner, snapshot.userId);
}

/** When the cards were saved, in the customer's language (Latin digits, as everywhere in the app). */
export function updatedAtLabel(
    generatedAt: string,
    locale: string,
    timeZone?: string,
): string {
    return new Intl.DateTimeFormat(`${locale}-u-nu-latn`, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone,
    }).format(new Date(generatedAt));
}

/**
 * The cards as of `now`: a last visit saved as "today" is "3 days ago"
 * three days later, counted on the phone's calendar.
 */
export function cardsAsOf(
    cards: MyCard[],
    generatedAt: string,
    now: Date,
): MyCard[] {
    const day = (date: Date) =>
        new Date(date.getFullYear(), date.getMonth(), date.getDate());
    // Rounded: a day of a clock change is 23 or 25 hours long.
    const days = Math.round(
        (day(now).getTime() - day(new Date(generatedAt)).getTime()) /
            86_400_000,
    );

    return cards.map((card) => ({
        ...card,
        lastVisitDaysAgo:
            card.lastVisitDaysAgo === null
                ? null
                : card.lastVisitDaysAgo + Math.max(days, 0),
    }));
}

/** The first of the phone's languages the app speaks, else English. */
export function phoneLocale(
    languages: readonly string[],
    supported: string[],
): string {
    return (
        languages
            .map((language) => language.split('-')[0].toLowerCase())
            .find((language) => supported.includes(language)) ?? 'en'
    );
}

/**
 * What the offline page shows (CHW-35): the cards kept on the phone, if
 * they are the last signed-in customer's, with the words of their
 * language (English without them).
 */
export async function readOfflineCards(): Promise<{
    snapshot: OfflineSnapshot;
    strings: Record<string, string>;
} | null> {
    const keptFor = owner();

    // Nobody's cards kept (signed out): never opened, so the database a sign-out deleted stays deleted.
    if (keptFor === null) {
        return null;
    }

    // The cards and their words in one read.
    const [snapshot, translations] = await open()
        .then((database) =>
            new Promise<
                [OfflineSnapshot | undefined, OfflineTranslations | undefined]
            >((resolve, reject) => {
                const store = database
                    .transaction(STORE, 'readonly')
                    .objectStore(STORE);
                const cards = store.get(CARDS);
                const words = store.get(TRANSLATIONS);
                store.transaction.oncomplete = () =>
                    resolve([cards.result, words.result]);
                store.transaction.onerror = () =>
                    reject(store.transaction.error);
            }).finally(() => database.close()),
        )
        .catch(() => [undefined, undefined] as const);

    if (!snapshot || !isOwnedBy(snapshot, keptFor)) {
        return null;
    }

    return {
        snapshot,
        strings:
            translations?.locale === snapshot.locale
                ? translations.strings
                : {},
    };
}

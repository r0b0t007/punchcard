import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { nextDeadline, readSecond, subscribeToSeconds } from '@/lib/seconds';

describe('subscribeToSeconds', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        // No DOM in unit tests: just the document's events and visibility.
        vi.stubGlobal(
            'document',
            Object.assign(new EventTarget(), { visibilityState: 'visible' }),
        );
        // Mid-second: the first tick waits for the next whole second.
        vi.setSystemTime(new Date('2026-10-26T10:00:05.400Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('ticks on each whole second, one timer for every reader', () => {
        const first = vi.fn();
        const second = vi.fn();
        const stopFirst = subscribeToSeconds(first);
        const stopSecond = subscribeToSeconds(second);

        vi.advanceTimersByTime(599);
        expect(first).not.toHaveBeenCalled();

        vi.advanceTimersByTime(10);
        expect(first).toHaveBeenCalledTimes(1);
        expect(second).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(3000);
        expect(first).toHaveBeenCalledTimes(4);
        expect(vi.getTimerCount()).toBe(1);

        stopFirst();
        stopSecond();
        expect(vi.getTimerCount()).toBe(0);
    });

    it('reads the time again when the page comes back', () => {
        const listener = vi.fn();
        const stop = subscribeToSeconds(listener);

        document.dispatchEvent(new Event('visibilitychange'));
        expect(listener).toHaveBeenCalledTimes(1);

        stop();
        document.dispatchEvent(new Event('visibilitychange'));
        expect(listener).toHaveBeenCalledTimes(1);
    });

    it('stops for good when the last reader leaves during a tick', () => {
        const stop = subscribeToSeconds(() => stop());

        vi.advanceTimersByTime(1000);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('keeps the whole second, read afresh while nobody is subscribed', () => {
        expect(readSecond()).toBe(Date.parse('2026-10-26T10:00:05.000Z'));

        vi.setSystemTime(new Date('2026-10-26T10:10:00.700Z'));
        expect(readSecond()).toBe(Date.parse('2026-10-26T10:10:00.000Z'));
    });
});

describe('nextDeadline', () => {
    it('keeps the earlier deadline, so a poll never adds a second back', () => {
        expect(nextDeadline(10_000, 11_000)).toBe(10_000);
        expect(nextDeadline(10_000, 9_000)).toBe(9_000);
    });

    it('takes a deadline more than a tick later: a new arming', () => {
        expect(nextDeadline(10_000, 12_001)).toBe(12_001);
        expect(nextDeadline(10_000, 12_000)).toBe(10_000);
    });
});

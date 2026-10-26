import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { subscribeToSeconds } from '@/lib/seconds';

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
});

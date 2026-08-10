import {afterEach, describe, expect, it, vi} from 'vitest';

import {createIFrameApiService, SILENT_AUTHORIZATION_COMPLETE_EVENT} from './IFrameApiService';

const ASSISTANT_ORIGIN = 'https://app.example.test';

const loadedFrame = (postMessage: (message: object, origin: string) => void) => ({
    dataset: {loaded: ''},
    contentWindow: {postMessage},
});

describe('IFrameApiService', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    /**
     * Without forwarding the retry counter into the recursive call the budget resets on every hop,
     * so the cap (and its support hint) is unreachable and a frame that never loads is polled
     * forever.
     */
    it('gives up after the retry cap when the assistant frame never loads', async () => {
        vi.useFakeTimers();
        const alertSpy = vi.fn();
        vi.stubGlobal('document', {getElementById: () => null});
        vi.stubGlobal('alert', alertSpy);

        createIFrameApiService(ASSISTANT_ORIGIN).notifySilentAuthorizationResult(true);

        await vi.advanceTimersByTimeAsync(250 * 30);

        expect(alertSpy).toHaveBeenCalledTimes(1);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('still delivers the message once the frame shows up within the budget', async () => {
        vi.useFakeTimers();
        const alertSpy = vi.fn();
        const postMessage = vi.fn();
        let lookups = 0;
        vi.stubGlobal('document', {
            getElementById: () => (++lookups > 3 ? loadedFrame(postMessage) : null),
        });
        vi.stubGlobal('alert', alertSpy);
        vi.spyOn(console, 'log').mockImplementation(() => undefined);

        createIFrameApiService(ASSISTANT_ORIGIN).notifySilentAuthorizationResult(true);

        await vi.advanceTimersByTimeAsync(250 * 5);

        expect(alertSpy).not.toHaveBeenCalled();
        expect(postMessage).toHaveBeenCalledTimes(1);
        expect(postMessage).toHaveBeenCalledWith(
            {version: '1.0', eventName: SILENT_AUTHORIZATION_COMPLETE_EVENT},
            ASSISTANT_ORIGIN
        );
    });
});

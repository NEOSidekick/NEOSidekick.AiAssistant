import {afterEach, describe, expect, it, vi} from 'vitest';

import {
    createEmbedTokenRequestHandler,
    EMBED_TOKEN_REQUEST_MESSAGE_TYPE,
    EMBED_TOKEN_RESPONSE_MESSAGE_TYPE,
    EMBED_TOKEN_URI,
    fetchEmbedToken,
} from './embedToken';

const ASSISTANT_ORIGIN = 'https://app.example.test';

describe('fetchEmbedToken', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('fetches the token same-origin with credentials and returns it', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({embed_token: 'embed-jwt'}),
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetchEmbedToken()).resolves.toBe('embed-jwt');

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [uri, init] = fetchMock.mock.calls[0];
        expect(uri).toBe(EMBED_TOKEN_URI);
        expect(init.credentials).toBe('include');
        // POST, never GET: a dead-session GET would be captured by Flow as the
        // intercepted request and poison the editor's next login redirect.
        expect(init.method).toBe('POST');
    });

    it('resolves null on a non-2xx response', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ok: false, status: 401}));

        await expect(fetchEmbedToken()).resolves.toBeNull();
    });

    it('resolves null on a malformed body', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({something: 'else'}),
        }));

        await expect(fetchEmbedToken()).resolves.toBeNull();
    });

    it('resolves null on a network error instead of rejecting', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('network down')));
        vi.spyOn(console, 'error').mockImplementation(() => undefined);

        await expect(fetchEmbedToken()).resolves.toBeNull();
    });

    it('aborts and resolves null when the request exceeds the timeout', async () => {
        vi.useFakeTimers();
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const fetchMock = vi.fn().mockImplementation(
            (_uri: string, init: {signal: AbortSignal}) =>
                new Promise((_resolve, reject) => {
                    init.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
                })
        );
        vi.stubGlobal('fetch', fetchMock);

        const pending = fetchEmbedToken(3000);
        await vi.advanceTimersByTimeAsync(3000);

        await expect(pending).resolves.toBeNull();
    });
});

describe('createEmbedTokenRequestHandler', () => {
    const requestMessage = (overrides: object = {}) => ({
        origin: ASSISTANT_ORIGIN,
        source: {postMessage: vi.fn()},
        data: {type: EMBED_TOKEN_REQUEST_MESSAGE_TYPE, requestId: 'request-1'},
        ...overrides,
    });

    it('ignores messages that are not embed-token requests', () => {
        const fetchToken = vi.fn();
        const handler = createEmbedTokenRequestHandler({fetchToken});

        const handled = handler(requestMessage({data: {eventName: 'get-content-tree'}}));

        expect(handled).toBe(false);
        expect(fetchToken).not.toHaveBeenCalled();
    });

    it('replies to the requesting window at its verified origin with the fetched token', async () => {
        const handler = createEmbedTokenRequestHandler({fetchToken: vi.fn().mockResolvedValue('embed-jwt')});
        const message = requestMessage();

        expect(handler(message)).toBe(true);
        await Promise.resolve();
        await Promise.resolve();

        expect(message.source.postMessage).toHaveBeenCalledTimes(1);
        expect(message.source.postMessage).toHaveBeenCalledWith(
            {
                type: EMBED_TOKEN_RESPONSE_MESSAGE_TYPE,
                requestId: 'request-1',
                embedToken: 'embed-jwt',
            },
            ASSISTANT_ORIGIN
        );
    });

    it('answers with embedToken null when the fetch fails', async () => {
        const handler = createEmbedTokenRequestHandler({fetchToken: vi.fn().mockRejectedValue(new Error('boom'))});
        const message = requestMessage();

        expect(handler(message)).toBe(true);
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(message.source.postMessage).toHaveBeenCalledWith(
            {
                type: EMBED_TOKEN_RESPONSE_MESSAGE_TYPE,
                requestId: 'request-1',
                embedToken: null,
            },
            ASSISTANT_ORIGIN
        );
    });

    it('drops a request without a usable requestId (handled, but never answered)', async () => {
        const fetchToken = vi.fn().mockResolvedValue('embed-jwt');
        const handler = createEmbedTokenRequestHandler({fetchToken});
        const message = requestMessage({data: {type: EMBED_TOKEN_REQUEST_MESSAGE_TYPE, requestId: 42}});

        expect(handler(message)).toBe(true);
        await Promise.resolve();
        await Promise.resolve();

        expect(fetchToken).not.toHaveBeenCalled();
        expect(message.source.postMessage).not.toHaveBeenCalled();
    });

    it('drops a request without a source window', () => {
        const fetchToken = vi.fn().mockResolvedValue('embed-jwt');
        const handler = createEmbedTokenRequestHandler({fetchToken});

        expect(handler(requestMessage({source: null}))).toBe(true);
        expect(fetchToken).not.toHaveBeenCalled();
    });
});

import {afterEach, describe, expect, it, vi} from 'vitest';

import {
    createSilentAuthorizationService,
    createSilentAuthorizeHandler,
    CsrfAwareFetcher,
    DO_AUTHORIZE_URI,
    SILENT_AUTHORIZE_EVENT,
} from './silentAuthorization';

type WithCsrfToken = CsrfAwareFetcher['withCsrfToken'];

const fetcherReturning = (withCsrfToken: WithCsrfToken): CsrfAwareFetcher => ({withCsrfToken});

describe('silentAuthorization', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('reports success when the backend accepts the re-authorization', async () => {
        const service = createSilentAuthorizationService({
            fetcher: fetcherReturning(async () => ({ok: true})),
        });

        await expect(service.authorize('laravel-state')).resolves.toBe(true);
    });

    it('posts the state form-urlencoded with the CSRF token in the header', async () => {
        const withCsrfToken = vi.fn<WithCsrfToken>(async () => ({ok: true}));
        const service = createSilentAuthorizationService({fetcher: fetcherReturning(withCsrfToken)});

        await service.authorize('laravel-state');

        const makeFetchRequest = withCsrfToken.mock.calls[0][0];
        const request = makeFetchRequest('the-csrf-token') as RequestInit & {url?: string, headers: Record<string, string>};

        expect(request.url).toBe(DO_AUTHORIZE_URI);
        expect(request.method).toBe('POST');
        expect(request.credentials).toBe('include');
        expect(request.headers['X-Flow-Csrftoken']).toBe('the-csrf-token');
        // A JSON body would leave Flow's `state` action argument empty and fail as a silent 422.
        expect(request.headers['Content-Type']).toBe('application/x-www-form-urlencoded');
        expect(request.body).toBe('state=laravel-state');
    });

    it('reports failure for a benign 422 (fetchWithErrorHandling resolves, but not ok)', async () => {
        const service = createSilentAuthorizationService({
            fetcher: fetcherReturning(async () => ({ok: false})),
        });

        await expect(service.authorize('laravel-state')).resolves.toBe(false);
    });

    it('reports failure when the fetcher rejects with a plain string (5xx)', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const service = createSilentAuthorizationService({
            fetcher: fetcherReturning(() => Promise.reject('Internal Server Error')),
        });

        await expect(service.authorize('laravel-state')).resolves.toBe(false);
    });

    it('reports failure when the fetcher throws synchronously (missing csrf token or url)', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const service = createSilentAuthorizationService({
            fetcher: fetcherReturning(() => {
                throw new Error('csrfToken not set');
            }),
        });

        await expect(service.authorize('laravel-state')).resolves.toBe(false);
    });

    it('reports failure once the timeout elapses on a promise that never settles (401 latch)', async () => {
        vi.useFakeTimers();
        const service = createSilentAuthorizationService({
            fetcher: fetcherReturning(() => new Promise(() => undefined)),
            timeoutMs: 5000,
        });

        const result = service.authorize('laravel-state');
        await vi.advanceTimersByTimeAsync(5000);

        await expect(result).resolves.toBe(false);
    });

    it('coalesces concurrent triggers onto one request and answers every caller', async () => {
        let settleRequest: (response: {ok: boolean}) => void = () => undefined;
        const withCsrfToken = vi.fn<WithCsrfToken>(() => new Promise(resolve => {
            settleRequest = resolve;
        }));
        const service = createSilentAuthorizationService({fetcher: fetcherReturning(withCsrfToken)});

        const first = service.authorize('laravel-state');
        const second = service.authorize('laravel-state');

        expect(withCsrfToken).toHaveBeenCalledTimes(1);

        settleRequest({ok: true});

        await expect(first).resolves.toBe(true);
        await expect(second).resolves.toBe(true);
    });

    it('allows a new attempt once the previous one settled', async () => {
        const withCsrfToken = vi.fn<WithCsrfToken>(async () => ({ok: true}));
        const service = createSilentAuthorizationService({fetcher: fetcherReturning(withCsrfToken)});

        await service.authorize('first-state');
        await service.authorize('second-state');

        expect(withCsrfToken).toHaveBeenCalledTimes(2);
    });

    it('does not coalesce a concurrent trigger for a different state onto the in-flight one', async () => {
        const settleByState = new Map<string, (response: {ok: boolean}) => void>();
        const postedStates: string[] = [];
        const withCsrfToken = vi.fn<WithCsrfToken>(makeFetchRequest => {
            const request = makeFetchRequest('the-csrf-token') as RequestInit & {body: string};
            const state = new URLSearchParams(request.body).get('state') as string;
            postedStates.push(state);

            return new Promise(resolve => {
                settleByState.set(state, resolve);
            });
        });
        const service = createSilentAuthorizationService({fetcher: fetcherReturning(withCsrfToken)});

        const first = service.authorize('state-a');
        const second = service.authorize('state-b');

        // Each state must reach the backend on its own request; sharing state A's answer would
        // leave state B unauthorized while telling its caller it succeeded.
        expect(withCsrfToken).toHaveBeenCalledTimes(2);
        expect(postedStates).toEqual(['state-a', 'state-b']);

        settleByState.get('state-a')!({ok: true});
        settleByState.get('state-b')!({ok: false});

        await expect(first).resolves.toBe(true);
        await expect(second).resolves.toBe(false);
    });
});

describe('createSilentAuthorizeHandler', () => {
    const messageFor = (state: unknown, eventName: string = SILENT_AUTHORIZE_EVENT) => ({
        data: {eventName, data: {state}},
    });

    const createHandler = (authorize = vi.fn(async () => true)) => {
        const notifyResult = vi.fn();

        return {authorize, notifyResult, handle: createSilentAuthorizeHandler({authorize, notifyResult})};
    };

    it('ignores messages for other events', () => {
        const {authorize, notifyResult, handle} = createHandler();

        handle(messageFor('laravel-state', 'get-content-tree'));

        expect(authorize).not.toHaveBeenCalled();
        expect(notifyResult).not.toHaveBeenCalled();
    });

    it.each([
        ['a missing state', undefined],
        ['an empty state', ''],
        ['a non-string state', 42],
    ])('answers false without authorizing for %s', (_label, state) => {
        const {authorize, notifyResult, handle} = createHandler();

        handle(messageFor(state));

        expect(authorize).not.toHaveBeenCalled();
        expect(notifyResult).toHaveBeenCalledTimes(1);
        expect(notifyResult).toHaveBeenCalledWith(false);
    });

    it('authorizes a valid state and notifies the result', async () => {
        const authorize = vi.fn(async () => false);
        const {notifyResult, handle} = createHandler(authorize);

        handle(messageFor('laravel-state'));
        await vi.waitFor(() => expect(notifyResult).toHaveBeenCalledTimes(1));

        expect(authorize).toHaveBeenCalledWith('laravel-state');
        expect(notifyResult).toHaveBeenCalledWith(false);
    });

    it('answers every trigger, so a duplicate never leaves the iframe polling', async () => {
        const {authorize, notifyResult, handle} = createHandler();

        handle(messageFor('laravel-state'));
        handle(messageFor('laravel-state'));
        await vi.waitFor(() => expect(notifyResult).toHaveBeenCalledTimes(2));

        expect(authorize).toHaveBeenCalledTimes(2);
        expect(notifyResult).toHaveBeenNthCalledWith(1, true);
        expect(notifyResult).toHaveBeenNthCalledWith(2, true);
    });
});

/**
 * Silent re-authorization against the Neos backend.
 *
 * The embedded assistant detects prior consent but a stale Laravel-side session and asks the parent
 * (this plugin) to mint a fresh JWT from the *live* Neos backend session. That is a same-origin,
 * CSRF-protected POST carrying the session cookie; on success Neos forwards the token to Laravel's
 * callback keyed by `state`.
 *
 * This module deliberately takes its fetcher as a dependency instead of importing the Neos UI
 * backend connector itself: the connector shim reads from the host plugin API at import time, which
 * only exists inside a running Neos UI. Injecting it keeps this logic unit-testable.
 */

export const DO_AUTHORIZE_URI = '/neosidekick/agent/do-authorize.json';

/**
 * postMessage event the embedded assistant sends to ask for a silent re-authorization.
 */
export const SILENT_AUTHORIZE_EVENT = 'neosidekick-silent-authorize';

/**
 * Upper bound for a single silent attempt. Needed because the injected fetcher may never settle
 * (see ACCEPTED RISK below) — without it the iframe's poller would wait out its whole budget.
 */
export const SILENT_AUTHORIZATION_TIMEOUT_MS = 5000;

type MakeFetchRequest = (csrfToken: string) => RequestInit & {url?: string};

/**
 * The slice of `fetchWithErrorHandling` this module relies on.
 */
export interface CsrfAwareFetcher {
    withCsrfToken(makeFetchRequest: MakeFetchRequest): Promise<{ok?: boolean} | undefined>;
}

export interface SilentAuthorizationOptions {
    fetcher: CsrfAwareFetcher;
    timeoutMs?: number;
}

export interface SilentAuthorizationService {
    /**
     * Resolves true only when the backend actually accepted the re-authorization. Never rejects.
     */
    authorize(state: string): Promise<boolean>;
}

/**
 * Runs a single silent do-authorize round trip and maps every outcome onto a boolean.
 *
 * Why the Neos UI connector rather than a raw `fetch`: a raw fetch follows the backend login
 * redirect to a 200 HTML page and would false-report success on a dead backend session — the whole
 * point of this rewrite. `fetchWithErrorHandling` never resolves such a request as ok.
 *
 * Its contract is unusual, hence the shape of the code below:
 *  - `withCsrfToken()` throws *synchronously* when no CSRF token has been registered or the `url`
 *    option is missing — so the call is wrapped in try/catch, not just `.catch()`.
 *  - it rejects with a plain string (the response body) on 5xx.
 *  - it resolves with the response for "not necessarily an error" statuses such as 404 and 422 —
 *    a benign 422 (already-consumed state) must therefore be read off `response.ok`.
 *  - on 401 it NEVER settles: the request is parked in a queue until a successful re-login.
 *
 * ACCEPTED RISK (recorded deliberately): that 401 behaviour lives on a shared singleton — a real
 * 401 here parks the whole Neos UI request pipeline until the editor re-logs in. That is arguably
 * the correct UX for a dead backend session (nothing else in the UI would work either), and it is
 * why the timeout race below exists: the silent flow still reports `false` promptly instead of
 * hanging on a promise that will never settle.
 */
const attemptSilentAuthorization = async (
    state: string,
    fetcher: CsrfAwareFetcher,
    timeoutMs: number
): Promise<boolean> => {
    let timeoutHandle: ReturnType<typeof setTimeout> | undefined;
    const timedOut = new Promise<boolean>(resolve => {
        timeoutHandle = setTimeout(() => resolve(false), timeoutMs);
    });

    try {
        const request = fetcher.withCsrfToken(csrfToken => ({
            url: DO_AUTHORIZE_URI,
            method: 'POST',
            credentials: 'include',
            headers: {
                // Flow accepts the CSRF token from this header; the body stays form-urlencoded
                // because a JSON body would leave Flow's `state` action argument empty and the
                // request would fail as a silent 422.
                'X-Flow-Csrftoken': csrfToken,
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
            },
            body: new URLSearchParams({state}).toString(),
        }));

        // Absorb the string rejection of a 5xx here (and any network error) so a timed-out attempt
        // can never surface later as an unhandled rejection.
        const settled = request.then(
            response => Boolean(response && response.ok),
            () => false
        );

        return await Promise.race([settled, timedOut]);
    } catch (error) {
        console.error('NEOSidekick silent authorization failed', error);
        return false;
    } finally {
        clearTimeout(timeoutHandle);
    }
};

/**
 * Creates the silent-authorization service.
 *
 * Coalescing is keyed by `state`, and that key is load-bearing rather than cosmetic: a trigger is
 * only a duplicate of another when both are re-authorizing the *same* Laravel state. Duplicates of
 * the same state share the single in-flight attempt — firing a second do-authorize would hit an
 * already-consumed `state` — but, unlike a plain "drop the duplicate" guard, every caller still
 * receives the result. A dropped trigger leaves the iframe's poller waiting for a message that
 * never arrives, until its budget expires.
 *
 * A trigger for a *different* state therefore must not be handed the in-flight answer for another
 * one: it would be answered about a state that was never sent, and its own would never be
 * authorized. It starts its own attempt instead. Running two attempts in parallel is safe — each
 * is an independent POST, and the iframe's result handling is settled-guarded and nonce-bound.
 */
export const createSilentAuthorizationService = (
    {fetcher, timeoutMs = SILENT_AUTHORIZATION_TIMEOUT_MS}: SilentAuthorizationOptions
): SilentAuthorizationService => {
    const pendingAttempts = new Map<string, Promise<boolean>>();

    return {
        authorize(state: string): Promise<boolean> {
            const pending = pendingAttempts.get(state);
            if (pending) {
                return pending;
            }

            const attempt = (async () => {
                try {
                    return await attemptSilentAuthorization(state, fetcher, timeoutMs);
                } finally {
                    // Retire only this attempt; a later trigger for the same state may already have
                    // claimed the slot.
                    if (pendingAttempts.get(state) === attempt) {
                        pendingAttempts.delete(state);
                    }
                }
            })();
            pendingAttempts.set(state, attempt);

            return attempt;
        },
    };
};

/**
 * The message shape the embedded assistant uses to ask for a silent re-authorization.
 */
export interface SilentAuthorizeMessage {
    data?: {
        eventName?: unknown;
        data?: {
            state?: unknown;
        };
    };
}

export interface SilentAuthorizeHandlerOptions {
    authorize: (state: string) => Promise<boolean>;
    notifyResult: (succeeded: boolean) => void;
}

/**
 * Builds the postMessage handler for the silent re-authorization request.
 *
 * Extracted from the manifest so the branch that actually decides whether a credential is minted is
 * unit-testable without booting the Neos UI.
 *
 * Every trigger is answered exactly once, including the rejected ones: an unanswered trigger leaves
 * the iframe's poller waiting out its full budget instead of falling back to the consent popup.
 */
export const createSilentAuthorizeHandler = (
    {authorize, notifyResult}: SilentAuthorizeHandlerOptions
) => (message: SilentAuthorizeMessage): void => {
    if (message.data?.eventName !== SILENT_AUTHORIZE_EVENT) {
        return;
    }

    const state = message.data?.data?.state;
    if (typeof state !== 'string' || state === '') {
        notifyResult(false);
        return;
    }

    authorize(state).then((succeeded) => {
        notifyResult(succeeded);
    });
};

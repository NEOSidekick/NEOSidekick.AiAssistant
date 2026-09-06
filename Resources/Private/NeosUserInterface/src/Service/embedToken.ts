/**
 * Embed-token issuance for the embedded assistant iframe.
 *
 * The embed token is a short-lived, single-use RS256 JWT minted by the plugin's
 * backend (POST /neosidekick/api/agentic/embed-token, backend-session only). It
 * gates the release of the assistant bootstrap's authBindingToken on the
 * NEOSidekick side: possessing one proves a live Neos backend session as
 * exactly the current editor.
 *
 * Two consumption paths:
 *  1. At iframe-src construction, SidekickIFrame fetches one and appends it as
 *     the `embedToken` query param. That token is spent on the first bootstrap;
 *     the SPA re-reads the frozen src on language switches, so it must never be
 *     treated as fresh afterwards.
 *  2. On demand: the embedded assistant posts
 *     { type: "neosidekick:embed-token:request", requestId, forcePush? } to the
 *     parent; the handler below - reached only through
 *     IFrameApiService.listenToMessages, which already verifies BOTH
 *     event.source === the assistant iframe's contentWindow AND
 *     event.origin === the configured apiDomain origin - fetches a fresh token
 *     same-origin and replies with
 *     { type: "neosidekick:embed-token:response", requestId, embedToken } via
 *     event.source.postMessage with event.origin as the explicit target origin
 *     (never "*"). Failures reply with embedToken: null so the assistant can
 *     fall back to its labeled authorization UX instead of waiting out a budget.
 *
 * The mint is also a push path, but only for a fetch that posts a JSON body:
 * the first-load fetch (path 1, no body) never pushes; every handshake reply
 * (path 2) posts {"forcePush": <bool>}, so the plugin backend pushes this
 * installation's signing key to NEOSidekick when that is due - or, on the
 * assistant's "Try again" after a rejected token, even when its record looks
 * fresh.
 */

export const EMBED_TOKEN_URI = '/neosidekick/api/agentic/embed-token';

export const EMBED_TOKEN_REQUEST_MESSAGE_TYPE = 'neosidekick:embed-token:request';
export const EMBED_TOKEN_RESPONSE_MESSAGE_TYPE = 'neosidekick:embed-token:response';

/**
 * Upper bound for one issuance fetch. A handshake fetch may spend up to 4 s in
 * signing-key round trips to NEOSidekick before the mint (two 2 s budgets: the
 * pending key chained, then the live key announced when that chain was
 * refused), which is where an installation switched between environments
 * heals. The first-load fetch never pushes and shares the bound for
 * simplicity. The assistant's own iframe budget is 8 s
 * (EMBED_TOKEN_REQUEST_TIMEOUT_MS), strictly greater than this 6 s fetch plus
 * the postMessage hop, so an answer always arrives before it gives up.
 */
export const EMBED_TOKEN_FETCH_TIMEOUT_MS = 6000;

export interface FetchEmbedTokenOptions {
    /**
     * Whether the plugin backend should push its signing key before minting.
     * Left undefined, the request carries no body and the backend does not
     * push at all (the first-load fetch). As a boolean it is sent as the JSON
     * body {"forcePush": <bool>}: false pushes when the backend's record says
     * a push is due, true pushes even when that record looks fresh. The
     * backend honours the body only under Content-Type: application/json
     * (which a cross-site simple request cannot set against the CSRF-exempt
     * action) and throttles forced pushes itself. A hint, not an instruction:
     * the push payload is built from the backend's local state alone.
     */
    forcePush?: boolean;
}

/**
 * Fetches a fresh embed token from the plugin backend, authenticated by the
 * live Neos backend session cookie. Resolves with the token string, or null on
 * any failure (non-2xx, malformed body, network error, timeout). Never rejects:
 * a missing token must degrade into the assistant's labeled state, never block
 * or crash the caller.
 *
 * POST, not GET (matching the backend route): when the backend session is dead,
 * Flow stores a session-authenticated GET as the intercepted request and the
 * editor's next login would redirect to this JSON response. The endpoint skips
 * CSRF protection server-side, so no token needs to ride along.
 *
 * Without a forcePush option the request carries no body and no content type;
 * with one it posts {"forcePush": <bool>} as JSON.
 */
export const fetchEmbedToken = async (
    {forcePush}: FetchEmbedTokenOptions = {},
    timeoutMs: number = EMBED_TOKEN_FETCH_TIMEOUT_MS
): Promise<string | null> => {
    const abortController = new AbortController();
    const timeoutHandle = setTimeout(() => abortController.abort(), timeoutMs);
    const postsBody = typeof forcePush === 'boolean';

    try {
        const response = await fetch(EMBED_TOKEN_URI, {
            method: 'POST',
            credentials: 'include',
            headers: postsBody
                ? {'Accept': 'application/json', 'Content-Type': 'application/json'}
                : {'Accept': 'application/json'},
            signal: abortController.signal,
            ...(postsBody ? {body: JSON.stringify({forcePush})} : {}),
        });

        if (!response.ok) {
            return null;
        }

        const body = await response.json();
        const embedToken = body?.embed_token;

        return typeof embedToken === 'string' && embedToken !== '' ? embedToken : null;
    } catch (error) {
        console.error('NEOSidekick embed token fetch failed', error);
        return null;
    } finally {
        clearTimeout(timeoutHandle);
    }
};

/**
 * The slice of the browser MessageEvent the handler relies on. origin/source
 * authenticity is established upstream by IFrameApiService.listenToMessages;
 * they are used here only to address the reply.
 */
export interface EmbedTokenRequestMessage {
    origin: string;
    source: {postMessage: (message: object, targetOrigin: string) => void} | null;
    data?: {
        type?: unknown;
        requestId?: unknown;
        forcePush?: unknown;
    };
}

export interface EmbedTokenRequestHandlerOptions {
    fetchToken: (options: FetchEmbedTokenOptions) => Promise<string | null>;
}

/**
 * Builds the postMessage handler for on-demand embed-token issuance. Returns
 * true when the message was an embed-token request (handled), false otherwise
 * so the caller's dispatch can fall through to the other message kinds.
 *
 * Every well-formed request is answered exactly once - errors as
 * embedToken: null - and the reply always targets the requesting window at its
 * verified origin explicitly. Every fetch posts a body, so the backend pushes
 * its signing key when due; a request carrying forcePush === true (strictly
 * the boolean) is fetched forced, anything else unforced.
 */
export const createEmbedTokenRequestHandler = (
    {fetchToken}: EmbedTokenRequestHandlerOptions
) => (message: EmbedTokenRequestMessage): boolean => {
    if (message.data?.type !== EMBED_TOKEN_REQUEST_MESSAGE_TYPE) {
        return false;
    }

    const {requestId} = message.data;
    const forcePush = message.data.forcePush === true;
    const requestSource = message.source;
    const requestOrigin = message.origin;
    if (typeof requestId !== 'string' || requestId === '' || !requestSource) {
        return true;
    }

    fetchToken({forcePush})
        .catch(() => null)
        .then((embedToken) => {
            requestSource.postMessage(
                {
                    type: EMBED_TOKEN_RESPONSE_MESSAGE_TYPE,
                    requestId,
                    embedToken,
                },
                requestOrigin
            );
        });

    return true;
};

import {afterEach, describe, expect, it, vi} from 'vitest';

import {
    createIFrameApiService,
    SEND_RETRY_BUDGET_MS,
    SEND_RETRY_INTERVAL_MS,
    SILENT_AUTHORIZATION_COMPLETE_EVENT,
} from './IFrameApiService';
import {EMBED_TOKEN_FETCH_TIMEOUT_MS} from './embedToken';

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
     * Without forwarding the waited time into the recursive call the budget resets on every hop,
     * so the cap (and its support hint) is unreachable and a frame that never loads is polled
     * forever.
     */
    it('gives up after the retry budget when the assistant frame never loads', async () => {
        vi.useFakeTimers();
        const alertSpy = vi.fn();
        vi.stubGlobal('document', {getElementById: () => null});
        vi.stubGlobal('alert', alertSpy);

        createIFrameApiService(ASSISTANT_ORIGIN).notifySilentAuthorizationResult(true);

        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS + SEND_RETRY_INTERVAL_MS);

        expect(alertSpy).toHaveBeenCalledTimes(1);
        expect(vi.getTimerCount()).toBe(0);
    });

    /**
     * SidekickIFrame renders the iframe only once the embed-token fetch settled, and the frame
     * then still has to load - so a budget inside that window alerts the editor on nothing but a
     * slow boot.
     */
    it('waits past the embed-token fetch timeout before giving up', () => {
        expect(SEND_RETRY_BUDGET_MS).toBeGreaterThan(EMBED_TOKEN_FETCH_TIMEOUT_MS);
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

        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS * 5);

        expect(alertSpy).not.toHaveBeenCalled();
        expect(postMessage).toHaveBeenCalledTimes(1);
        expect(postMessage).toHaveBeenCalledWith(
            {version: '1.0', eventName: SILENT_AUTHORIZATION_COMPLETE_EVENT},
            ASSISTANT_ORIGIN
        );
    });

    /**
     * callModule raises the streaming latch before sending, and only the assistant's "finished"
     * event lowers it - which cannot arrive for a call it never received. An abandoned call
     * therefore used to queue every later one behind it for the rest of the page's life.
     */
    it('lets a later AI call through after a call-module was abandoned', async () => {
        vi.useFakeTimers();
        const alertSpy = vi.fn();
        const postMessage = vi.fn();
        let frame: object | null = null;
        vi.stubGlobal('document', {getElementById: () => frame});
        vi.stubGlobal('alert', alertSpy);
        vi.spyOn(console, 'log').mockImplementation(() => undefined);

        const service = createIFrameApiService(ASSISTANT_ORIGIN);
        service.callModule({moduleName: 'first'});
        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS + SEND_RETRY_INTERVAL_MS);

        expect(alertSpy).toHaveBeenCalledTimes(1);
        expect(postMessage).not.toHaveBeenCalled();

        frame = loadedFrame(postMessage);
        service.callModule({moduleName: 'second'});

        expect(postMessage).toHaveBeenCalledTimes(1);
        expect(postMessage.mock.calls[0][0]).toMatchObject({
            eventName: 'call-module',
            data: {platform: 'neos', moduleName: 'second'},
        });
    });

    /**
     * The SPA derives the default site of document listings from this field, so the response
     * must always carry it - an empty string when the store holds no site node.
     */
    describe('respondWithContentTree', () => {
        const sendAndCaptureMessage = (contentTree: unknown, siteNodeName: string) => {
            const postMessage = vi.fn();
            vi.stubGlobal('document', {getElementById: () => loadedFrame(postMessage)});
            vi.spyOn(console, 'log').mockImplementation(() => undefined);

            createIFrameApiService(ASSISTANT_ORIGIN).respondWithContentTree(contentTree, siteNodeName);

            return postMessage.mock.calls[0];
        };

        it('sends the content tree together with the site node name', () => {
            const contentTree = {generatedAt: '2026-09-06T00:00:00.000Z', rootNode: {id: 'abc'}};

            expect(sendAndCaptureMessage(contentTree, 'academy')).toEqual([
                {
                    version: '1.0',
                    eventName: 'content-tree-response',
                    data: {contentTree, siteNodeName: 'academy'},
                },
                ASSISTANT_ORIGIN,
            ]);
        });

        it('still sends the site node name key when it is unknown', () => {
            expect(sendAndCaptureMessage(null, '')).toEqual([
                {
                    version: '1.0',
                    eventName: 'content-tree-response',
                    data: {contentTree: null, siteNodeName: ''},
                },
                ASSISTANT_ORIGIN,
            ]);
        });
    });

    /**
     * listenToMessages is the SOLE authenticity gate of the embed-token postMessage
     * channel (and every other inbound message): only a message whose source is the
     * assistant iframe's contentWindow AND whose origin is the configured assistant
     * origin may reach a handler. Any hole here hands the embed-token issuance to an
     * arbitrary frame.
     */
    describe('listenToMessages', () => {
        const assistantContentWindow = {name: 'assistant-content-window'};

        const setUpListener = (frame: object | null = {contentWindow: assistantContentWindow}) => {
            const messageListeners: Array<(event: object) => void> = [];
            vi.stubGlobal('window', {
                addEventListener: (type: string, listener: (event: object) => void) => {
                    if (type === 'message') {
                        messageListeners.push(listener);
                    }
                },
            });
            vi.stubGlobal('document', {
                getElementById: (id: string) => (id === 'neosidekickAssistant' ? frame : null),
            });

            const handler = vi.fn();
            createIFrameApiService(ASSISTANT_ORIGIN).listenToMessages(handler);

            return {
                handler,
                dispatch: (event: object) => messageListeners.forEach((listener) => listener(event)),
            };
        };

        it('delivers a message from the assistant iframe window at the configured origin', () => {
            const {handler, dispatch} = setUpListener();
            const event = {source: assistantContentWindow, origin: ASSISTANT_ORIGIN, data: {type: 'x'}};

            dispatch(event);

            expect(handler).toHaveBeenCalledTimes(1);
            expect(handler).toHaveBeenCalledWith(event);
        });

        it('ignores a message from a different origin even when the source is the assistant frame', () => {
            const {handler, dispatch} = setUpListener();

            dispatch({source: assistantContentWindow, origin: 'https://evil.example.test', data: {type: 'x'}});

            expect(handler).not.toHaveBeenCalled();
        });

        it('ignores a message whose source is not the assistant iframe contentWindow', () => {
            const {handler, dispatch} = setUpListener();

            dispatch({source: {name: 'some-other-window'}, origin: ASSISTANT_ORIGIN, data: {type: 'x'}});

            expect(handler).not.toHaveBeenCalled();
        });

        it('ignores every message while the assistant frame is not in the document', () => {
            const {handler, dispatch} = setUpListener(null);

            dispatch({source: assistantContentWindow, origin: ASSISTANT_ORIGIN, data: {type: 'x'}});

            expect(handler).not.toHaveBeenCalled();
        });
    });
});

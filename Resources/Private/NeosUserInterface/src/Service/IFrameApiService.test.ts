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

/**
 * Lets time pass on the monotonic clock without running any timer, which is what a throttled
 * background tab does to a pending retry. Call it after vi.useFakeTimers().
 */
const createMonotonicClock = () => {
    const fakeNow = performance.now.bind(performance);
    let skipped = 0;
    vi.spyOn(performance, 'now').mockImplementation(() => fakeNow() + skipped);

    return {
        skip: (milliseconds: number): void => {
            skipped += milliseconds;
        },
    };
};

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
     * slow boot. The boot is played through here: comparing the budget with the fetch timeout
     * alone holds for any allowance on top of it, however small.
     */
    it('still delivers after an embed-token fetch that timed out and a slow frame load', async () => {
        const SLOW_FRAME_LOAD_MS = 10000;
        vi.useFakeTimers();
        const alertSpy = vi.fn();
        const postMessage = vi.fn();
        let frame: object | null = null;
        vi.stubGlobal('document', {getElementById: () => frame});
        vi.stubGlobal('alert', alertSpy);
        vi.spyOn(console, 'log').mockImplementation(() => undefined);

        createIFrameApiService(ASSISTANT_ORIGIN).updateWebContext(true, {page: 'first'});
        await vi.advanceTimersByTimeAsync(EMBED_TOKEN_FETCH_TIMEOUT_MS + SLOW_FRAME_LOAD_MS);

        expect(alertSpy).not.toHaveBeenCalled();

        frame = loadedFrame(postMessage);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);

        expect(alertSpy).not.toHaveBeenCalled();
        expect(postMessage).toHaveBeenCalledTimes(1);
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
     * The assistant answers a delivered call, which is how its caller learns that it is over. An
     * abandoned call is answered by nobody, so the caller has to be told here - the AI modal
     * otherwise keeps its spinner and, once closed, cancels a call that is not its own.
     */
    it('tells the caller that its call was abandoned', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('alert', vi.fn());
        vi.stubGlobal('document', {getElementById: () => null});
        const onStarted = vi.fn();
        const onAbandoned = vi.fn();

        createIFrameApiService(ASSISTANT_ORIGIN).callModule({moduleName: 'abandoned'}, onStarted, onAbandoned);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS - SEND_RETRY_INTERVAL_MS);

        expect(onAbandoned).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);

        expect(onAbandoned).toHaveBeenCalledTimes(1);
        expect(onStarted).not.toHaveBeenCalled();
    });

    it('does not report a delivered call as abandoned', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('alert', vi.fn());
        const postMessage = vi.fn();
        let frame: object | null = null;
        vi.stubGlobal('document', {getElementById: () => frame});
        vi.spyOn(console, 'log').mockImplementation(() => undefined);
        const onStarted = vi.fn();
        const onAbandoned = vi.fn();

        createIFrameApiService(ASSISTANT_ORIGIN).callModule({moduleName: 'delivered'}, onStarted, onAbandoned);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);
        frame = loadedFrame(postMessage);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS * 2);

        expect(onStarted).toHaveBeenCalledTimes(1);
        expect(onAbandoned).not.toHaveBeenCalled();
    });

    /**
     * Creating a node with several generated properties queues one call per property. Giving each
     * a budget of its own keeps the latch up for as many budgets as there are calls, and ends
     * every one of them in the same alert.
     */
    it('gives up on the calls queued behind an abandoned call together with it', async () => {
        vi.useFakeTimers();
        const alertSpy = vi.fn();
        const postMessage = vi.fn();
        let frame: object | null = null;
        vi.stubGlobal('document', {getElementById: () => frame});
        vi.stubGlobal('alert', alertSpy);
        vi.spyOn(console, 'log').mockImplementation(() => undefined);
        const onAbandoned = [vi.fn(), vi.fn(), vi.fn()];

        const service = createIFrameApiService(ASSISTANT_ORIGIN);
        onAbandoned.forEach((callback, index) => service.callModule({moduleName: `call-${index}`}, undefined, callback));
        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS);

        onAbandoned.forEach((callback) => expect(callback).toHaveBeenCalledTimes(1));
        expect(alertSpy).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS * 3);

        expect(alertSpy).toHaveBeenCalledTimes(1);

        frame = loadedFrame(postMessage);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);
        service.callModule({moduleName: 'later'});

        expect(postMessage.mock.calls.map(([message]) => message)).toMatchObject([
            {eventName: 'call-module', data: {moduleName: 'later'}},
        ]);
    });

    /**
     * Every message runs out of budget on its own. With a frame that never loads, an alert per
     * message blocks the editor once for every page they open.
     */
    it('reports a frame that never loads once, not once per message', async () => {
        vi.useFakeTimers();
        const alertSpy = vi.fn();
        vi.stubGlobal('document', {getElementById: () => null});
        vi.stubGlobal('alert', alertSpy);

        const service = createIFrameApiService(ASSISTANT_ORIGIN);
        service.updateWebContext(true, {page: 'first'});
        await vi.advanceTimersByTimeAsync(5000);
        service.updateWebContext(true, {page: 'second'});
        await vi.advanceTimersByTimeAsync(5000);
        service.callModule({moduleName: 'call'});
        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS * 2);

        expect(alertSpy).toHaveBeenCalledTimes(1);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('reports a frame that goes missing again after it had been reachable', async () => {
        vi.useFakeTimers();
        const alertSpy = vi.fn();
        let frame: object | null = null;
        vi.stubGlobal('document', {getElementById: () => frame});
        vi.stubGlobal('alert', alertSpy);
        vi.spyOn(console, 'log').mockImplementation(() => undefined);

        const service = createIFrameApiService(ASSISTANT_ORIGIN);
        service.notifySilentAuthorizationResult(true);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS);

        expect(alertSpy).toHaveBeenCalledTimes(1);

        frame = loadedFrame(vi.fn());
        service.notifySilentAuthorizationResult(true);
        frame = null;
        service.notifySilentAuthorizationResult(true);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS);

        expect(alertSpy).toHaveBeenCalledTimes(2);
    });

    /**
     * Web context arriving during a call is deferred until the call is finished. Releasing an
     * abandoned call without sending it would hold it back until the NEXT call finished, where
     * it could overwrite newer context sent in between.
     */
    it('sends web context deferred during an abandoned call ahead of the next call', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('alert', vi.fn());
        const postMessage = vi.fn();
        let frame: object | null = null;
        vi.stubGlobal('document', {getElementById: () => frame});
        vi.spyOn(console, 'log').mockImplementation(() => undefined);

        const service = createIFrameApiService(ASSISTANT_ORIGIN);
        service.callModule({moduleName: 'abandoned'});
        service.updateWebContext(true, {page: 'deferred'});
        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS);

        frame = loadedFrame(postMessage);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);
        service.callModule({moduleName: 'next'});

        expect(postMessage.mock.calls.map(([message]) => message)).toMatchObject([
            {eventName: 'page-changed', data: {page: 'deferred'}},
            {eventName: 'call-module', data: {moduleName: 'next'}},
        ]);
    });

    /**
     * alert() blocks the window until the editor closes it: timers stand still while the clock
     * runs on. Web context sent on its way before the alert has used up its budget by the time
     * the alert is closed, and is dropped on its first retry although the frame is about to load.
     */
    it('starts the budget of the deferred web context only once the alert is closed', async () => {
        vi.useFakeTimers();
        const monotonicClock = createMonotonicClock();
        vi.stubGlobal('alert', () => monotonicClock.skip(SEND_RETRY_BUDGET_MS));
        const postMessage = vi.fn();
        let frame: object | null = null;
        vi.stubGlobal('document', {getElementById: () => frame});
        vi.spyOn(console, 'log').mockImplementation(() => undefined);

        const service = createIFrameApiService(ASSISTANT_ORIGIN);
        service.callModule({moduleName: 'abandoned'});
        service.updateWebContext(true, {page: 'deferred'});
        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS + SEND_RETRY_INTERVAL_MS);

        frame = loadedFrame(postMessage);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);

        expect(postMessage.mock.calls.map(([message]) => message)).toMatchObject([
            {eventName: 'page-changed', data: {page: 'deferred'}},
        ]);
    });

    /**
     * A call cancelled while it still waits for the frame is taken back. Left retrying, it would
     * reach the assistant after all, and failing that end in the alert - for a call nobody wants
     * any more.
     */
    it('takes back a cancelled call that never reached the frame', async () => {
        vi.useFakeTimers();
        const alertSpy = vi.fn();
        vi.stubGlobal('alert', alertSpy);
        const postMessage = vi.fn();
        let frame: object | null = null;
        vi.stubGlobal('document', {getElementById: () => frame});
        vi.spyOn(console, 'log').mockImplementation(() => undefined);
        const onStarted = vi.fn();
        const onAbandoned = vi.fn();

        const service = createIFrameApiService(ASSISTANT_ORIGIN);
        service.callModule({moduleName: 'cancelled'}, onStarted, onAbandoned);
        await vi.advanceTimersByTimeAsync(1000);
        service.cancelCallModule();
        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);

        expect(vi.getTimerCount()).toBe(0);

        service.callModule({moduleName: 'current'});
        await vi.advanceTimersByTimeAsync(1000);
        frame = loadedFrame(postMessage);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS * 2);

        expect(postMessage.mock.calls.map(([message]) => message)).toMatchObject([
            {eventName: 'call-module', data: {moduleName: 'current'}},
        ]);
        expect(onStarted).not.toHaveBeenCalled();
        expect(onAbandoned).not.toHaveBeenCalled();
        expect(alertSpy).not.toHaveBeenCalled();
    });

    it('still cancels a call the assistant has received', () => {
        const postMessage = vi.fn();
        vi.stubGlobal('document', {getElementById: () => loadedFrame(postMessage)});
        vi.spyOn(console, 'log').mockImplementation(() => undefined);

        const service = createIFrameApiService(ASSISTANT_ORIGIN);
        service.callModule({moduleName: 'delivered'});
        service.cancelCallModule();

        expect(postMessage.mock.calls.map(([message]) => message)).toMatchObject([
            {eventName: 'call-module', data: {moduleName: 'delivered'}},
            {eventName: 'cancel-call-module'},
        ]);
    });

    /**
     * The call that replaced a cancelled one holds the latch on its own. The cancelled call
     * running out of budget must not release it, which would let a third call run alongside.
     */
    it('does not let a cancelled call release the call that replaced it', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('alert', vi.fn());
        const postMessage = vi.fn();
        let frame: object | null = null;
        vi.stubGlobal('document', {getElementById: () => frame});
        vi.spyOn(console, 'log').mockImplementation(() => undefined);

        const service = createIFrameApiService(ASSISTANT_ORIGIN);
        service.callModule({moduleName: 'cancelled'});
        await vi.advanceTimersByTimeAsync(1000);
        service.cancelCallModule();
        service.callModule({moduleName: 'current'});
        await vi.advanceTimersByTimeAsync(SEND_RETRY_BUDGET_MS - 1000);

        frame = loadedFrame(postMessage);
        service.callModule({moduleName: 'queued'});

        expect(postMessage).not.toHaveBeenCalled();
    });

    /**
     * Background tabs throttle timers to a second or more, so the budget is measured in elapsed
     * time; counting hops would stretch it many times over.
     */
    it('measures the budget in elapsed time, so throttled timers cannot stretch it', async () => {
        vi.useFakeTimers();
        const monotonicClock = createMonotonicClock();
        const alertSpy = vi.fn();
        vi.stubGlobal('document', {getElementById: () => null});
        vi.stubGlobal('alert', alertSpy);

        createIFrameApiService(ASSISTANT_ORIGIN).notifySilentAuthorizationResult(true);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);
        monotonicClock.skip(SEND_RETRY_BUDGET_MS);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);

        expect(alertSpy).toHaveBeenCalledTimes(1);
    });

    /**
     * The wall clock is not a stopwatch: it jumps when the system time is corrected or the machine
     * wakes from sleep. Such a jump must not be counted as time the message waited for the frame.
     */
    it('does not count a jump of the system clock as waiting time', async () => {
        vi.useFakeTimers();
        const alertSpy = vi.fn();
        const postMessage = vi.fn();
        let frame: object | null = null;
        vi.stubGlobal('document', {getElementById: () => frame});
        vi.stubGlobal('alert', alertSpy);
        vi.spyOn(console, 'log').mockImplementation(() => undefined);

        createIFrameApiService(ASSISTANT_ORIGIN).notifySilentAuthorizationResult(true);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);
        vi.setSystemTime(Date.now() + SEND_RETRY_BUDGET_MS);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);

        expect(alertSpy).not.toHaveBeenCalled();

        frame = loadedFrame(postMessage);
        await vi.advanceTimersByTimeAsync(SEND_RETRY_INTERVAL_MS);

        expect(postMessage).toHaveBeenCalledTimes(1);
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

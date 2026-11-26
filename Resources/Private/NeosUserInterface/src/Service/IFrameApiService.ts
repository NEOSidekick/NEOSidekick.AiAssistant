
import {EMBED_TOKEN_FETCH_TIMEOUT_MS} from './embedToken';

export const SILENT_AUTHORIZATION_COMPLETE_EVENT = 'neosidekick-agent-silent-authorization-complete';
export const SILENT_AUTHORIZATION_FAILED_EVENT = 'neosidekick-agent-silent-authorization-failed';

export const SEND_RETRY_INTERVAL_MS = 250;

/**
 * How long a message waits for the assistant frame before it is given up on.
 *
 * It must stay well above the time the frame needs to become addressable on a slow boot:
 * SidekickIFrame renders the iframe only once the embed-token fetch settled
 * (EMBED_TOKEN_FETCH_TIMEOUT_MS), and getAssistantFrame() additionally waits for that
 * iframe's onLoad. A budget below that sum turns a slow backend into the "could not load"
 * alert while the frame is still on its way.
 */
export const SEND_RETRY_BUDGET_MS = EMBED_TOKEN_FETCH_TIMEOUT_MS + 24000;

export const createIFrameApiService = (assistantFrameOrigin: string): IFrameApiService => {
    return new IFrameApiService(assistantFrameOrigin);
}

interface ModuleCall {
    data: object;
    onStarted?: () => void;
    onAbandoned?: () => void;
}

/** The call holding the streaming latch, from callModule until it is finished. */
interface CurrentCall {
    message: object;
    onAbandoned?: () => void;
    /** 'waiting' for the frame, 'delivered' to it, or 'cancelled' before it got there. */
    state: 'waiting' | 'delivered' | 'cancelled';
}

export class IFrameApiService {
    private isStreaming: boolean = false;
    private unsyncedWebContextIsNewPage?: boolean;
    private unsyncedWebContextData?: object;
    private callModuleQueue: Array<ModuleCall> = [];
    private currentCall?: CurrentCall;
    private frameFailureReported: boolean = false;

    /**
     * @param assistantFrameOrigin Origin of the embedded assistant iframe (the Laravel apiDomain).
     *        Used to target outbound postMessage calls and to reject inbound messages from any
     *        other origin.
     */
    constructor(private readonly assistantFrameOrigin: string) {}

    /**
     * The function calling this must reset isStreaming after the call is done.
     *
     * @param onStarted Called once the call was handed to the assistant frame.
     * @param onAbandoned Called instead when the frame never became ready and the call was given
     *        up on. No event of the assistant follows such a call, so a caller that shows progress
     *        has to end it here.
     */
    callModule = (data: object, onStarted?: () => void, onAbandoned?: () => void): void => {
        // If busy, disallow further call-module events and throw error
        if (this.isStreaming) {
            this.callModuleQueue.push({data, onStarted, onAbandoned});
            return;
        }

        this.isStreaming = true;
        const message = {
            version: '1.0',
            eventName: 'call-module',
            data: {
                'platform': 'neos',
                ...data,
            }
        }
        const call: CurrentCall = {message, onAbandoned, state: 'waiting'};
        this.currentCall = call;
        this.sendMessage(
            message,
            () => {
                call.state = 'delivered';
                onStarted?.();
            },
            () => call.state === 'cancelled'
        );
    }

    setStreamingFinished = (): void => {
        this.isStreaming = false;
        this.currentCall = undefined;
        if (this.unsyncedWebContextData) {
            this.updateWebContext(this.unsyncedWebContextIsNewPage || false, this.unsyncedWebContextData);
            this.unsyncedWebContextData = undefined;
            this.unsyncedWebContextIsNewPage = undefined;
        }
        const nextCallModule = this.callModuleQueue.shift();
        if (nextCallModule) {
            const {data, onStarted, onAbandoned} = nextCallModule;
            this.callModule(data, onStarted, onAbandoned);
        }
    }

    cancelCallModule = (): void => {
        const message = {
            version: '1.0',
            eventName: 'cancel-call-module'
        };
        const cancelledCall = this.currentCall;
        this.setStreamingFinished();

        // A call still waiting for the frame is taken back here instead: its retry stops, so it
        // neither reaches the assistant after all nor ends in the "could not load" alert. The
        // assistant never saw it, so there is nothing to cancel on its side - and a cancel
        // message waiting for the frame in its place could hit a later call.
        if (cancelledCall?.state === 'waiting') {
            cancelledCall.state = 'cancelled';
            return;
        }

        this.sendMessage(message);
    }

    updateWebContext = (isNewPage: boolean, data: object): void => {
        if (this.isStreaming) {
            // if we are currently streaming, only send a new web context before a new call-module
            this.unsyncedWebContextIsNewPage = isNewPage;
            this.unsyncedWebContextData = data;
            return;
        }

        const message = {
            version: '1.0',
            eventName: isNewPage ? 'page-changed' : 'page-updated',
            data: data,
        };
        this.sendMessage(message);
    }

    listenToMessages = (fn: Function): void => {
        // Attach immediately rather than waiting for the frame to finish loading. The embedded
        // assistant can post a one-shot message (e.g. the silent re-auth trigger) the moment it
        // mounts — potentially before getAssistantFrame() would succeed. Gating attachment on the
        // loaded state used to drop those early messages. The frame is resolved per-message instead.
        window.addEventListener('message', message => {
            const assistantFrame = document.getElementById('neosidekickAssistant') as HTMLIFrameElement | null;
            // Bind to both the frame window and its origin so a message can only come from
            // the embedded assistant, never from another frame that happens to share the source.
            if (assistantFrame && message.source === assistantFrame.contentWindow && message.origin === this.assistantFrameOrigin) {
                fn(message);
            }
        });
    }

    /**
     * Notifies the embedded assistant whether a silent re-authorization succeeded, so it can
     * reload on success or fall back to the consent popup on failure.
     */
    notifySilentAuthorizationResult = (succeeded: boolean): void => {
        this.sendMessage({
            version: '1.0',
            eventName: succeeded ? SILENT_AUTHORIZATION_COMPLETE_EVENT : SILENT_AUTHORIZATION_FAILED_EVENT,
        });
    }

    /**
     * @param siteNodeName Node name of the site the editor currently works on, empty when unknown.
     * @param contentDimensions The configured content dimensions with their presets (Neos UI `cr.contentDimensions.byName`).
     * @param activeDimensions The dimension values the editor works in, read together with the content tree.
     */
    respondWithContentTree = (contentTree: unknown, siteNodeName: string, contentDimensions: unknown = null, activeDimensions: unknown = null): void => {
        this.sendMessage({
            version: '1.0',
            eventName: 'content-tree-response',
            data: {
                contentTree,
                siteNodeName,
                contentDimensions,
                activeDimensions,
            },
        });
    }

    /**
     * @param isWithdrawn Asked before every attempt; a message that was withdrawn while it waited
     *        for the frame is dropped without a word.
     */
    private sendMessage = (
        message: object,
        onSend?: Function,
        isWithdrawn: () => boolean = () => false,
        startedAt: number = performance.now()
    ): void => {
        if (isWithdrawn()) {
            return;
        }

        const assistantFrame = this.getAssistantFrame();
        if (assistantFrame) {
            this.frameFailureReported = false;
            console.log('Sending message to frame', message);
            assistantFrame.contentWindow?.postMessage(message, this.assistantFrameOrigin);

            if (onSend) {
                onSend();
            }
        } else {
            // Elapsed time, not counted hops: browsers throttle timers in background tabs to a
            // second or more, which would stretch a hop-counted budget many times over. It is read
            // from the monotonic clock, because the wall clock jumps - a corrected system time or
            // a machine waking from sleep would count as waiting, or take waiting time back.
            // The start must be carried into the retry, or the cap is never reached.
            if (performance.now() - startedAt >= SEND_RETRY_BUDGET_MS) {
                this.abandonMessage(message);
                return;
            }
            setTimeout(() => this.sendMessage(message, onSend, isWithdrawn, startedAt), SEND_RETRY_INTERVAL_MS);
        }
    }

    /**
     * A message the frame never became ready for.
     *
     * callModule raises the streaming latch before sending, and only the assistant's own
     * "finished" event lowers it again - which can never arrive for a call it never received.
     * The abandoned call is therefore finished here instead, exactly as a delivered one would
     * be: the latch drops and web context deferred during the call goes out ahead of any later
     * call. Only the call holding the latch may do this, never any other message.
     *
     * The calls queued behind it are given up on with it. They waited for the same frame, and a
     * budget of their own for each would keep the latch up for as many budgets as there are
     * calls - a single node with several generated properties queues that many at once.
     *
     * Their callers are told as well: they would otherwise wait for an answer that cannot come,
     * and later cancel a call that is not their own.
     */
    private abandonMessage = (message: object): void => {
        // Report first, release second. The alert blocks this window for as long as the editor
        // leaves it open - timers stand still, the clock does not. Whatever the release sends
        // starts its budget when it is sent, so sent ahead of the alert it would have used that
        // budget up, unattempted, by the time the alert is closed.
        this.reportFrameFailure();

        if (message === this.currentCall?.message) {
            const abandonedCalls = [this.currentCall, ...this.callModuleQueue];
            this.callModuleQueue = [];
            this.setStreamingFinished();
            abandonedCalls.forEach(({onAbandoned}) => onAbandoned?.());
        }
    }

    /**
     * Tells the editor that the assistant frame could not be loaded - once per outage, not once
     * per message. Every message runs out of budget on its own, and each raising its own
     * blocking alert interrupts the editor again and again over the same missing frame. The
     * outage is over as soon as the frame takes a message.
     */
    private reportFrameFailure = (): void => {
        if (this.frameFailureReported) {
            return;
        }

        this.frameFailureReported = true;
        alert('NEOSidekick AI-Error: Could not load assistant frame, please reload the page or contact support@neosidekick.com.');
    }

    private getAssistantFrame = (): HTMLIFrameElement|null => {
        const assistantFrame = document.getElementById('neosidekickAssistant') as HTMLIFrameElement;
        const isLoaded = assistantFrame?.dataset.hasOwnProperty('loaded') && assistantFrame.contentWindow;
        return isLoaded ? assistantFrame : null;
    }
}

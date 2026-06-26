
export const SILENT_AUTHORIZATION_COMPLETE_EVENT = 'neosidekick-agent-silent-authorization-complete';
export const SILENT_AUTHORIZATION_FAILED_EVENT = 'neosidekick-agent-silent-authorization-failed';

export const createIFrameApiService = (assistantFrameOrigin: string): IFrameApiService => {
    return new IFrameApiService(assistantFrameOrigin);
}

interface ModuleCall {
    data: object;
    onStarted?: () => void;
}

export class IFrameApiService {
    private isStreaming: boolean = false;
    private unsyncedWebContextIsNewPage?: boolean;
    private unsyncedWebContextData?: object;
    private callModuleQueue: Array<ModuleCall> = [];

    /**
     * @param assistantFrameOrigin Origin of the embedded assistant iframe (the Laravel apiDomain).
     *        Used to target outbound postMessage calls and to reject inbound messages from any
     *        other origin.
     */
    constructor(private readonly assistantFrameOrigin: string) {}

    // the function calling this, must reset isStreaming after the call is done
    callModule = (data: object, onStarted?: () => void): void => {
        // If busy, disallow further call-module events and throw error
        if (this.isStreaming) {
            this.callModuleQueue.push({data, onStarted});
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
        this.sendMessage(message, onStarted);
    }

    setStreamingFinished = (): void => {
        this.isStreaming = false;
        if (this.unsyncedWebContextData) {
            this.updateWebContext(this.unsyncedWebContextIsNewPage || false, this.unsyncedWebContextData);
            this.unsyncedWebContextData = undefined;
            this.unsyncedWebContextIsNewPage = undefined;
        }
        const nextCallModule = this.callModuleQueue.shift();
        if (nextCallModule) {
            const {data, onStarted} = nextCallModule;
            this.callModule(data, onStarted);
        }
    }

    cancelCallModule = (): void => {
        const message = {
            version: '1.0',
            eventName: 'cancel-call-module'
        };
        this.setStreamingFinished();
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

    respondWithContentTree = (contentTree: unknown): void => {
        this.sendMessage({
            version: '1.0',
            eventName: 'content-tree-response',
            data: {
                contentTree,
            },
        });
    }

    private sendMessage = (message: object, onSend?: Function, retiesCount: number = 0): void => {
        const assistantFrame = this.getAssistantFrame();
        if (assistantFrame) {
            console.log('Sending message to frame', message);
            assistantFrame.contentWindow?.postMessage(message, this.assistantFrameOrigin);

            if (onSend) {
                onSend();
            }
        } else {
            if (retiesCount > 20) {
                alert('NEOSidekick AI-Error: Could not load assistant frame, please reload the page or contact support@neosidekick.com.');
                return;
            }
            retiesCount++;
            setTimeout(() => this.sendMessage(message, onSend), 250);
        }
    }

    private getAssistantFrame = (): HTMLIFrameElement|null => {
        const assistantFrame = document.getElementById('neosidekickAssistant') as HTMLIFrameElement;
        const isLoaded = assistantFrame?.dataset.hasOwnProperty('loaded') && assistantFrame.contentWindow;
        return isLoaded ? assistantFrame : null;
    }
}

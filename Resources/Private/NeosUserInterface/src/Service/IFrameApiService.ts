
export const createIFrameApiService = (): IFrameApiService => {
    return new IFrameApiService();
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
        const assistantFrame = this.getAssistantFrame();
        if (assistantFrame) {
            window.addEventListener('message', message => {
                if (message.source === assistantFrame.contentWindow) {
                    fn(message);
                }
            });
        } else {
            setTimeout(() => this.listenToMessages(fn), 250);
        }
    }

    private sendMessage = (message: object, onSend?: Function, retiesCount: number = 0): void => {
        const assistantFrame = this.getAssistantFrame();
        if (assistantFrame) {
            console.log('Sending message to frame', message);
            assistantFrame.contentWindow?.postMessage(message, '*');

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

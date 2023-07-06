import {SynchronousMetaRegistry} from "@neos-project/neos-ui-extensibility";
import {Store} from 'react-redux'

export const createAssistantService = (globalRegistry: SynchronousMetaRegistry<any>, store: Store): AssistantService => {
    return new AssistantService(globalRegistry, store)
}

export class AssistantService {
    private globalRegistry: SynchronousMetaRegistry<any>;
    private store: Store;
    constructor(globalRegistry: SynchronousMetaRegistry<any>, store: Store) {
        this.globalRegistry = globalRegistry
        this.store = store
    }

    sendMessageToIframe = (message): void => {
        const checkLoadedStatusAndSendMessage = setInterval(() => {
            const assistantFrame = document.getElementById('neosidekickAssistant')
            const isLoaded = assistantFrame.dataset.hasOwnProperty('loaded')
            if (isLoaded) {
                console.log('loaded, sending message to frame', message)
                // @ts-ignore
                assistantFrame.contentWindow.postMessage(message, '*')
                clearInterval(checkLoadedStatusAndSendMessage)
            } else {
                console.log('not loaded, waiting...')
                return
            }
        }, 250)
    }

    listenToMessages = (): void => {
        const checkLoadedStatusAndSendMessage = setInterval(() => {
            const assistantFrame = document.getElementById('neosidekickAssistant')
            const isLoaded = assistantFrame?.dataset?.hasOwnProperty('loaded')
            if (isLoaded) {
                window.addEventListener('message', message => {
                    const iframe = assistantFrame.contentWindow
                    if (message.source === iframe) {
                        console.log(message)
                        this.handleMessage(message)
                    }
                });
                clearInterval(checkLoadedStatusAndSendMessage)
            } else {
                return
            }
        }, 250)
    }

    private handleMessage = (message): void => {
        if (message?.data?.eventName === 'write-content') {
            const {nodePath, propertyName, value, isFinished} = message.data.data;
            this.changePropertyValue(nodePath, propertyName, value, isFinished)
        }
    }

    private changePropertyValue = (nodePath: string, propertyName: string, propertyValue: string, isFinished: bool = false): void => {
        const guestFrame = document.getElementsByName('neos-content-main')[0];
        // @ts-ignore
        const guestFrameDocument = guestFrame?.contentDocument;
        const inlineField = guestFrameDocument.querySelector(`[data-__neos-editable-node-contextpath="${nodePath}"][data-__neos-property="${propertyName}"]`)
        if (inlineField) {
            // @ts-ignore
            inlineField?.ckeditorInstance?.setData(propertyValue)
            inlineField.classList.toggle('typing-caret', !isFinished)
        }
    }
}

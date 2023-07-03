import {SynchronousMetaRegistry} from "@neos-project/neos-ui-extensibility";
import {Node, NodeType} from '@neos-project/neos-ts-interfaces';
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

    sendMessageToIframe = (message) => {
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

    listenToMessages = () => {
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

    private handleMessage = message => {
        if (message.data.eventName === 'write-content') {
            const {contextPath, propertyName, propertyValue} = message.data.data;
            this.changePropertyValue(contextPath, propertyName, propertyValue)
        }
    }

    private changePropertyValue = (contextPath, propertyName, propertyValue) => {
        const guestFrame = document.getElementsByName('neos-content-main')[0];
        // @ts-ignore
        const guestFrameDocument = guestFrame?.contentDocument;
        const inlineField = guestFrameDocument.querySelector(`[data-__neos-editable-node-contextpath="${contextPath}"][data-__neos-property="${propertyName}"]`)
        setTimeout(() => {
            inlineField.ckeditorInstance.setData(propertyValue)
        }, 100)
    }
}

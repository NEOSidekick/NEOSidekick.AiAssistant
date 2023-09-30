import {SynchronousMetaRegistry} from "@neos-project/neos-ui-extensibility";
import {Store} from 'react-redux'
import {actions} from '@neos-project/neos-ui-redux-store'

export const createAssistantService = (globalRegistry: SynchronousMetaRegistry<any>, store: Store): AssistantService => {
    return new AssistantService(globalRegistry, store)
}

export class AssistantService {
    private globalRegistry: SynchronousMetaRegistry<any>;
    private store: Store;
    public currentlyHandledNodePath: string;
    constructor(globalRegistry: SynchronousMetaRegistry<any>, store: Store) {
        this.globalRegistry = globalRegistry
        this.store = store
        this.currentlyHandledNodePath = null
    }

    sendMessageToIframe = (message): void => {
        if (this.currentlyHandledNodePath && message?.eventName === 'call-module') {
            this.addFlashMessage('1695668144026', 'You cannot start two text generations at the same time.')
            return
        }

        if (message?.eventName === 'call-module') {
            this.currentlyHandledNodePath = message?.data?.target?.nodePath
        }

        const checkLoadedStatusAndSendMessage = setInterval(() => {
            const assistantFrame = document.getElementById('neosidekickAssistant')
            const isLoaded = assistantFrame.dataset.hasOwnProperty('loaded')
            if (isLoaded) {
                console.log('loaded, sending message to frame', message)
                // @ts-ignore
                assistantFrame.contentWindow.postMessage(message, '*')
                clearInterval(checkLoadedStatusAndSendMessage)

                if (message?.eventName === 'call-module') {
                    // If nothing happens for a while, reset handledNodePath
                    this.resetCurrentlyHandledNodePathDebounced()
                }
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
        console.info('Handle Sidekick Message: ' + message.data.eventName)
        if (message?.data?.eventName === 'write-content') {
            const {nodePath, propertyName, value, isFinished} = message.data.data;
            // Make sure the handledNodePath is set while we alter the content
            this.currentlyHandledNodePath = nodePath
            this.changePropertyValue(nodePath, propertyName, value, isFinished)
        } else if (message?.data?.eventName === 'stopped-generation') {
            this.resetTypingCaret()
            this.resetCurrentlyHandledNodePath()
        } else if (message?.data?.eventName === 'error') {
            const errorMessage = message?.data?.data?.message;
            this.addFlashMessage('1688158257149', 'An error occurred while asking NEOSidekick: ' + errorMessage, 'error', errorMessage)
            this.resetTypingCaret()
            this.resetCurrentlyHandledNodePath()
        }
        // Make sure that the handledNodePath is reset eventually
        this.resetCurrentlyHandledNodePathDebounced()
    }

    private resetTypingCaret() {
        const guestFrame = document.getElementsByName('neos-content-main')[0];
        // @ts-ignore
        const guestFrameDocument = guestFrame?.contentDocument;
        const inlineFieldWithTypingCaret = guestFrameDocument.querySelector(`.typing-caret`)
        if (inlineFieldWithTypingCaret) {
            inlineFieldWithTypingCaret.classList.remove('typing-caret')
        }
    }

    private changePropertyValue = (nodePath: string, propertyName: string, propertyValue: string, isFinished: bool = false): void => {
        const guestFrame = document.getElementsByName('neos-content-main')[0];
        // @ts-ignore
        const guestFrameDocument = guestFrame?.contentDocument;
        const inlineField = guestFrameDocument.querySelector(`[data-__neos-editable-node-contextpath="${nodePath}"][data-__neos-property="${propertyName}"]`)
        if (!inlineField) {
            this.resetCurrentlyHandledNodePath()
            return;
        }
        // Check if node is still present
        const state = this.store.getState()
        if (!state?.cr?.nodes?.byContextPath.hasOwnProperty(nodePath)) {
            inlineField.classList.remove('typing-caret')
            this.resetCurrentlyHandledNodePath()
            return;
        }
        // @ts-ignore
        inlineField?.ckeditorInstance?.setData(propertyValue)
        inlineField?.classList.toggle('typing-caret', !isFinished)

        if (isFinished) {
            this.resetCurrentlyHandledNodePath()
        }
    }

    public resetCurrentlyHandledNodePath(): void
    {
        this.currentlyHandledNodePath = null
    }

    public resetCurrentlyHandledNodePathDebounced(): void
    {
        let timer, timeout = 1000
        return () => {
            clearTimeout(timer)
            timer = setTimeout(() => {
                this.currentlyHandledNodePath = null
            }, timeout)
        }
    }

    private addFlashMessage(code: string, message: string, severity: string = 'error', externalMessage: string = null): void {
        const i18nRegistry = this.globalRegistry.get('i18n')
        this.store.dispatch(actions.UI.FlashMessages.add(code, i18nRegistry.translate('NEOSidekick.AiAssistant:Error:' + code, message, {0: externalMessage}), severity))
    }
}

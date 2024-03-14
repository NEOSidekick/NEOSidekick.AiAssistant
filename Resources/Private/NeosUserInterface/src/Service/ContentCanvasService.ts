import {SynchronousMetaRegistry} from "@neos-project/neos-ui-extensibility";
// @ts-ignore
import {Store} from 'react-redux'
import {actions} from '@neos-project/neos-ui-redux-store'
import {IFrameApiService} from "./IFrameApiService";

export const createContentCanvasService = (globalRegistry: SynchronousMetaRegistry<any>, store: Store, iFrameApiService: IFrameApiService): ContentCanvasService => {
    return new ContentCanvasService(globalRegistry, store, iFrameApiService);
}

export class ContentCanvasService {
    private globalRegistry: SynchronousMetaRegistry<any>;
    private store: Store;
    private iFrameApiService: IFrameApiService;
    private currentlyHandledNodePath: string | null = null;

    constructor(globalRegistry: SynchronousMetaRegistry<any>, store: Store, iFrameApiService: IFrameApiService) {
        this.globalRegistry = globalRegistry;
        this.store = store;
        this.iFrameApiService = iFrameApiService;

        this.iFrameApiService.listenToMessages(this.handleMessage);
    }

    streamGenerationIntoInlineProperty = (nodePath: string, propertyName: string, data: object): void => {
        // If busy, disallow further call-module events and show error
        if (this.currentlyHandledNodePath) {
            this.addFlashMessage('1695668144026', 'You cannot start two text generations at the same time.');
            return;
        }

        this.iFrameApiService.callModule({
            'platform': 'neos',
            'target': {
                'nodePath': nodePath,
                'propertyName': propertyName
            },
            ...data
        });
    }

    onNodeRemoved = (nodePath: string): void => {
        if (this.currentlyHandledNodePath === nodePath) {
            this.iFrameApiService.cancelCallModule();
            this.unsetCurrentlyHandledNodePath();
        }
    }

    private unsetCurrentlyHandledNodePath(): void {
        this.currentlyHandledNodePath = null;
    }

    private handleMessage = (message: object): void => {
        switch (message?.data?.eventName) {
            case 'write-content':
                const {nodePath, propertyName, value, isFinished} = message.data.data;
                // Make sure the handledNodePath is set while we alter the content
                this.currentlyHandledNodePath = nodePath;
                console.info(nodePath + ': ' + value);
                this.setPropertyValue(nodePath, propertyName, value);
                if (isFinished) {
                    this.handleStreamingFinished();
                }
                break;
            case 'stopped-generation':
                this.handleStreamingFinished();
                break;
            case 'error':
                let errorMessage = message?.data?.data?.message;
                this.addFlashMessage('1688158257149', 'An error occurred while asking NEOSidekick: ' + errorMessage, 'error', errorMessage);
                this.handleStreamingFinished();
                break;
            default:
                const errorMessage2 = 'Unknown message event: ' + message?.data?.eventName;
                this.addFlashMessage('1688158257149', 'An error occurred while asking NEOSidekick: ' + errorMessage2, 'error', errorMessage2);
                this.handleStreamingFinished();
        }
    }

    private handleStreamingFinished = () => {
        this.resetTypingCaret();
        this.unsetCurrentlyHandledNodePath();
        this.iFrameApiService.setStreamingFinished();
    }

    private resetTypingCaret() {
        const guestFrame = document.getElementsByName('neos-content-main')[0] as HTMLIFrameElement;
        const guestFrameDocument = guestFrame?.contentDocument;
        const inlineFieldWithTypingCaret = guestFrameDocument?.querySelector(`.typing-caret`);
        if (inlineFieldWithTypingCaret) {
            inlineFieldWithTypingCaret.classList.remove('typing-caret')
        }
    }

    private setPropertyValue = (nodePath: string, propertyName: string, propertyValue: string): void => {
        const guestFrame = document.getElementsByName('neos-content-main')[0] as HTMLIFrameElement;
        const guestFrameDocument = guestFrame?.contentDocument;
        const inlineField = guestFrameDocument?.querySelector(`[data-__neos-editable-node-contextpath="${nodePath}"][data-__neos-property="${propertyName}"]`);
        if (!inlineField) {
            const errorMessage = 'Could not find inline field for nodePath: ' + nodePath + ' and propertyName: ' + propertyName;
            this.addFlashMessage('1688158257149', 'An error occurred while asking NEOSidekick: ' + errorMessage, 'error', errorMessage);
            this.unsetCurrentlyHandledNodePath();
            return;
        }

        // Check if node is still present
        const state = this.store.getState();
        if (!state?.cr?.nodes?.byContextPath.hasOwnProperty(nodePath)) {
            this.handleStreamingFinished();
            return;
        }
        // @ts-ignore
        inlineField.ckeditorInstance?.setData(propertyValue);
        inlineField.classList.add('typing-caret');
    }

    private addFlashMessage(code: string, message: string, severity: "success" |"info" | "error" = 'error', externalMessage?: string): void {
        const i18nRegistry = this.globalRegistry.get('i18n');
        this.store.dispatch(actions.UI.FlashMessages.add(code, i18nRegistry.translate('NEOSidekick.AiAssistant:Error:' + code, message, {0: externalMessage}), severity));
    }
}

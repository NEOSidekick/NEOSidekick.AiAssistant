import {SynchronousMetaRegistry} from "@neos-project/neos-ui-extensibility";
// @ts-ignore
import {Store} from 'react-redux'
import {actions} from '@neos-project/neos-ui-redux-store'
import {IFrameApiService} from "./IFrameApiService";
import {ServerStreamMessage} from "../interfaces";

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
        this.iFrameApiService.callModule({
            'target': {
                'nodePath': nodePath,
                'propertyName': propertyName
            },
            ...data
        }, () => this.currentlyHandledNodePath = nodePath);
    }

    onNodeRemoved = (nodePath: string): void => {
        if (this.currentlyHandledNodePath === nodePath) {
            this.iFrameApiService.cancelCallModule();
            this.unsetCurrentlyHandledNodePath();
        }
    }

    getEditorContent = (nodePath: string, propertyName: string): string => {
        const inlineField = this.getPropertyInlineField(nodePath, propertyName);
        const editor = inlineField?.ckeditorInstance;
        return editor?.getData();
    }

    getSelectedContent = (nodePath: string, propertyName: string): string => {
        const inlineField = this.getPropertyInlineField(nodePath, propertyName);
        const editor = inlineField?.ckeditorInstance;
        return editor?.data.stringify(editor.model.getSelectedContent(editor.model.document.selection));
    }

    insertTextIntoInlineEditor = (nodePath: string, propertyName: string, htmlText: string, endsWithSpace: boolean): void => {
        const inlineField = this.getPropertyInlineField(nodePath, propertyName);
        const editor = inlineField?.ckeditorInstance;
        const range = editor.model.document.selection.getFirstRange();

        if (endsWithSpace) {
            let closingBracketsPosition = htmlText.lastIndexOf('</');
            if ((closingBracketsPosition + 10) < htmlText.length) { // position seems wrong
                closingBracketsPosition = htmlText.length;
            }
            htmlText = htmlText.substring(0, closingBracketsPosition) + '&nbsp;' + htmlText.substring(closingBracketsPosition);
        }

        // see https://ckeditor.com/docs/ckeditor5/latest/api/module_engine_model_model-Model.html#function-insertContent
        editor.model.change(writer => {
            const viewFragment = editor.data.processor.toView(htmlText);
            const modelFragment = editor.data.toModel(viewFragment);
            const newRange = editor.model.insertContent(modelFragment, range);
            editor.editing.view.focus();
            writer.setSelection(newRange);
        });
    }

    private unsetCurrentlyHandledNodePath(): void {
        this.currentlyHandledNodePath = null;
    }

    private handleMessage = (message: ServerStreamMessage): void => {
        switch (message?.data?.eventName) {
            case 'write-content':
                const {nodePath, propertyName, value, isFinished} = message?.data?.data;
                // Make sure the handledNodePath is set while we alter the content
                if (nodePath && propertyName) { // is this a message for the content canvas?
                    console.info(nodePath + ': ' + value);
                    this.setPropertyValue(nodePath, propertyName, value || '', isFinished || false);
                    if (isFinished) {
                        this.handleStreamingFinished();
                    }
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

    private getPropertyInlineField = (nodePath: string, propertyName: string) => {
        // editors are not globally registered, so we need a hacky dom query
        const guestFrame = document.getElementsByName('neos-content-main')[0] as HTMLIFrameElement;
        const guestFrameDocument = guestFrame?.contentDocument;
        return guestFrameDocument?.querySelector(`[data-__neos-editable-node-contextpath="${nodePath}"][data-__neos-property="${propertyName}"]`) || undefined;
    }

    private setPropertyValue = (nodePath: string, propertyName: string, propertyValue: string, isFinished: boolean): void => {
        const inlineField = this.getPropertyInlineField(nodePath, propertyName);
        if (!inlineField && isFinished) { // can initially be undefined, on a new page with onCreate generation
            const errorMessage = 'Could not find inline field for nodePath: ' + nodePath + ' and propertyName: ' + propertyName;
            this.addFlashMessage('1688158257149', 'An error occurred while asking NEOSidekick: ' + errorMessage, 'error', errorMessage);
            this.unsetCurrentlyHandledNodePath();
            return;
        }
        if (!inlineField) {
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

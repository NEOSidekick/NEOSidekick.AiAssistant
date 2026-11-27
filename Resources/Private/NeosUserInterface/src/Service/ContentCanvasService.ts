import {SynchronousMetaRegistry} from "@neos-project/neos-ui-extensibility";
// @ts-ignore
import {Store} from 'react-redux'
// @ts-ignore
import {actions, actionTypes} from '@neos-project/neos-ui-redux-store'
import backend from '@neos-project/neos-ui-backend-connector';
import {IFrameApiService} from "./IFrameApiService";
import {ServerStreamMessage} from "../interfaces";

const DOCUMENT_NODE_PATH_PROTOCOL = 'document-node-path://';
const CONTENT_NODE_PATH_PROTOCOL = 'content-node-path://';
const UUID_REGEX = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
/** Posted by the review package's popup mode to the window that opened it. */
const REVIEW_RESULT_MESSAGE_TYPE = 'workspace-review:result';

export const createContentCanvasService = (globalRegistry: SynchronousMetaRegistry<any>, store: Store, iFrameApiService: IFrameApiService): ContentCanvasService => {
    return new ContentCanvasService(globalRegistry, store, iFrameApiService);
}

export class ContentCanvasService {
    private globalRegistry: SynchronousMetaRegistry<any>;
    private store: Store;
    private iFrameApiService: IFrameApiService;
    private currentlyHandledNodePath: string | null = null;
    private documentNavigationRequest: number = 0;
    private workspaceInfoRefreshPromise: Promise<void> | null = null;
    private workspaceInfoRefreshQueued: boolean = false;
    /** The review popups this window opened, each with the id of its review session. */
    private reviewWindows: Map<Window, string> = new Map();
    /** Results already forwarded; a page view of the popup reports each one once, this guards the rest. */
    private forwardedReviewResults: Set<string> = new Set();
    private listensToReviewPopups: boolean = false;

    constructor(globalRegistry: SynchronousMetaRegistry<any>, store: Store, iFrameApiService: IFrameApiService) {
        this.globalRegistry = globalRegistry;
        this.store = store;
        this.iFrameApiService = iFrameApiService;

        this.iFrameApiService.listenToMessages(this.handleMessage);
    }

    /**
     * Results of the review popups this window opened (Workspace Review package, presentation
     * "popup"). The popup is a page of this Neos instance, so only messages of this origin from
     * a window opened here are accepted. Each result refreshes the editor at once - the popup may
     * close right after - and goes to the assistant.
     */
    private handleReviewPopupMessage = (event: MessageEvent): void => {
        if (event.origin !== window.location.origin || event.data?.type !== REVIEW_RESULT_MESSAGE_TYPE) {
            return;
        }
        const reviewSessionId = event.source ? this.reviewWindows.get(event.source as Window) : undefined;
        const result = event.data?.result;
        const resultId = result?.resultId;
        if (!reviewSessionId || event.data?.version !== 1 || typeof resultId !== 'string' || this.forwardedReviewResults.has(resultId)) {
            return;
        }
        this.forwardedReviewResults.add(resultId);
        this.reloadAfterContentChange();
        this.iFrameApiService.sendReviewResult(reviewSessionId, result);
    }

    private registerReviewWindow(reviewWindow: Window, requestedSessionId?: string): void {
        if (!this.listensToReviewPopups) {
            window.addEventListener('message', this.handleReviewPopupMessage);
            this.listensToReviewPopups = true;
        }
        // A popup the editor closed sends nothing any more.
        this.reviewWindows.forEach((_, knownWindow) => {
            if (knownWindow.closed) {
                this.reviewWindows.delete(knownWindow);
            }
        });
        // The chat generates an ID per click and binds it to the originating message/thread.
        // Older chat clients omit it and retain the previous uncorrelated result behavior.
        const reviewSessionId = typeof requestedSessionId === 'string' && UUID_REGEX.test(requestedSessionId)
            ? requestedSessionId
            : typeof crypto?.randomUUID === 'function'
                ? crypto.randomUUID()
                : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
        this.reviewWindows.set(reviewWindow, reviewSessionId);
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
        const eventName = message?.data?.eventName;
        // if no eventName is present in the message data,
        // we assume it's not an event of our own and we ignore it.
        // This bug was found by using the BitWarden browser extension
        // which triggered own messages in the iFrame
        if (!eventName) {
            return;
        }
        switch (eventName) {
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
            case 'reload-content':
                this.reloadAfterContentChange();
                break;
            case 'show-document-node':
                this.navigateToDocumentNodePath(message?.data?.data?.documentNodeId || message?.data?.data?.href);
                break;
            case 'show-content-node':
                this.focusContentNodePath(message?.data?.data?.contentNodeId || message?.data?.data?.href);
                break;
            case 'review-document-node':
                this.openDocumentReview(message?.data?.data?.documentNodeId, message?.data?.data?.documents, message?.data?.data?.reviewSessionId);
                break;
            case 'get-content-tree':
            case 'neosidekick-silent-authorize':
                // handled in manifest.tsx; this service only handles canvas-modification events
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

    private reloadAfterContentChange(): void {
        this.refreshWorkspaceInfo();
        this.reloadDocumentNodeTree();
        this.reloadContentCanvas();
    }

    private refreshWorkspaceInfo(): void {
        if (this.workspaceInfoRefreshPromise) {
            // A refresh is already in flight. Its response was computed before the content
            // change that triggered THIS call, so simply returning would leave the publish
            // state stale (the canvas/tree reloads run unconditionally, but this one would
            // be dropped). Queue exactly one trailing refresh instead.
            this.workspaceInfoRefreshQueued = true;
            return;
        }

        const {getWorkspaceInfo} = backend.get().endpoints;

        this.workspaceInfoRefreshPromise = getWorkspaceInfo()
            .then((workspaceInfo: unknown) => {
                this.store.dispatch(actions.CR.Workspaces.update(workspaceInfo));
            })
            .catch((error: unknown) => {
                console.error('Could not refresh Neos workspace info after NEOSidekick content update.', error);
            })
            .finally(() => {
                this.workspaceInfoRefreshPromise = null;
                if (this.workspaceInfoRefreshQueued) {
                    this.workspaceInfoRefreshQueued = false;
                    this.refreshWorkspaceInfo();
                }
            });
    }

    private reloadDocumentNodeTree(): void {
        // Reuse the same action as the document-tree refresh button.
        this.store.dispatch(actions.CR.Nodes.reloadState({merge: true}));
    }

    private reloadContentCanvas(): void {
        const state = this.store.getState();
        const uri = state?.ui?.contentCanvas?.previewUrl || state?.ui?.contentCanvas?.src;
        if (!uri) {
            return;
        }

        this.store.dispatch({
            type: actionTypes.UI.ContentCanvas.RELOAD,
            payload: {
                uri,
            }
        });
    }

    private async navigateToDocumentNodePath(rawDocumentNodePath?: string): Promise<void> {
        const rawTarget = rawDocumentNodePath?.trim().replace(/^show-document-node:\/\//, '');
        const documentNodePath = this.normalizeNodePath(rawTarget, DOCUMENT_NODE_PATH_PROTOCOL);
        if (!documentNodePath) {
            return;
        }

        // `<uuid>?language=en` opens the same page in other content dimensions; dimensions the
        // link does not name keep their active value.
        const requestedDimensions = this.parseRequestedDimensions(rawTarget);
        if (requestedDimensions.length > 0 && UUID_REGEX.test(documentNodePath)) {
            const targetDimensions = this.resolveTargetDimensions(requestedDimensions);
            if (!targetDimensions) {
                this.addFlashMessage('neosidekick-dimension-link', 'The link points to a language or dimension that is not configured.', 'error');
                return;
            }
            if (!this.equalsActiveDimensions(targetDimensions)) {
                await this.navigateToDocumentInDimensions(documentNodePath, targetDimensions, requestedDimensions);
                return;
            }
        }

        const navigationRequest = ++this.documentNavigationRequest;
        const state = this.store.getState();
        const currentDocumentNodePath = state?.cr?.nodes?.documentNode;
        const currentContext = currentDocumentNodePath?.split('@')[1];
        const allNodes = Object.values(state?.cr?.nodes?.byContextPath || {});
        const normalizedIdentifier = documentNodePath.replace(/^\/+/, '');
        const normalizedContextPath = documentNodePath.startsWith('/') ? documentNodePath : '/' + documentNodePath;
        const matchesTarget = (node: any): boolean => {
            const contextPath = node?.contextPath;
            const identifier = node?.identifier;
            const contextMatches = typeof contextPath === 'string' &&
                (!currentContext || contextPath.split('@')[1] === currentContext);
            const contextPathMatches = typeof contextPath === 'string' && contextPath.split('@')[0] === normalizedContextPath;
            const identifierMatches = typeof identifier === 'string' && identifier === normalizedIdentifier;
            return contextMatches && (contextPathMatches || identifierMatches);
        };
        let targetNode = allNodes.find(matchesTarget) as any;
        let loadedFromBackend = false;

        // The UI store only contains pages loaded so far. Resolve missing/incomplete
        // entries in the active document's workspace and content dimensions.
        if ((!targetNode?.contextPath || !targetNode?.uri) && currentDocumentNodePath) {
            try {
                const {q} = backend.get();
                const selector = UUID_REGEX.test(normalizedIdentifier) ? '#' + normalizedIdentifier : normalizedContextPath;
                const nodes = await q(currentDocumentNodePath).find(selector).get();
                targetNode = nodes.find(matchesTarget);
                loadedFromBackend = true;
            } catch {
                targetNode = undefined;
            }
        }

        // A later click or manual page/dimension change supersedes this lookup.
        if (navigationRequest !== this.documentNavigationRequest ||
            this.store.getState()?.cr?.nodes?.documentNode !== currentDocumentNodePath) {
            return;
        }

        if (!targetNode?.contextPath || !targetNode?.uri) {
            const errorMessage = 'Could not resolve document node path: ' + documentNodePath;
            this.addFlashMessage('1688158257150', 'An error occurred while asking NEOSidekick: ' + errorMessage, 'error', errorMessage);
            return;
        }

        if (loadedFromBackend) {
            this.store.dispatch(actions.CR.Nodes.merge({[targetNode.contextPath]: targetNode}));
        }
        this.store.dispatch(actions.UI.ContentCanvas.setSrc(targetNode.uri));
        this.store.dispatch(actions.CR.Nodes.setDocumentNode(targetNode.contextPath));
    }

    /**
     * Resolves the page like the Neos dimension switcher does. Loading its URI into the canvas
     * makes the guest frame switch the active dimensions, which reloads the document tree.
     */
    private async navigateToDocumentInDimensions(identifier: string, targetDimensions: Record<string, string[]>, requestedDimensions: Array<[string, string]>): Promise<void> {
        const navigationRequest = ++this.documentNavigationRequest;
        const state = this.store.getState();
        const currentDocumentNodePath = state?.cr?.nodes?.documentNode;
        let result: {nodeFound?: boolean; nodeFrontendUri?: string; nodeContextPath?: string} | undefined;
        try {
            result = await backend.get().endpoints.getSingleNode(identifier, {
                dimensions: targetDimensions,
                workspaceName: state?.cr?.workspaces?.personalWorkspace?.name
            });
        } catch {
            // The Neos backend connector already reported the failed request.
            return;
        }

        // A later click or manual page/dimension change supersedes this lookup.
        if (navigationRequest !== this.documentNavigationRequest ||
            this.store.getState()?.cr?.nodes?.documentNode !== currentDocumentNodePath) {
            return;
        }

        if (!result?.nodeFound || !result.nodeFrontendUri) {
            const labels = requestedDimensions.map(([dimensionName, value]) => this.presetLabel(dimensionName, value)).join(', ');
            this.addFlashMessage('neosidekick-dimension-link', `This page does not exist in ${labels} yet.`, 'info');
            return;
        }

        this.store.dispatch(actions.UI.ContentCanvas.setSrc(result.nodeFrontendUri));
        if (result.nodeContextPath) {
            this.store.dispatch(actions.UI.PageTree.focus(result.nodeContextPath));
        }
    }

    private parseRequestedDimensions(rawTarget?: string): Array<[string, string]> {
        const queryStart = rawTarget?.indexOf('?') ?? -1;
        if (!rawTarget || queryStart === -1) {
            return [];
        }
        const query = rawTarget.slice(queryStart + 1).split('#')[0];
        return [...new URLSearchParams(query).entries()].filter(([name, value]) => name !== '' && value !== '');
    }

    /**
     * Each named dimension takes the full fallback chain of the preset matching the link value
     * by preset key, otherwise by its first value. Null when a name or value is not configured.
     */
    private resolveTargetDimensions(requestedDimensions: Array<[string, string]>): Record<string, string[]> | null {
        const contentDimensions = this.store.getState()?.cr?.contentDimensions;
        const targetDimensions: Record<string, string[]> = {...(contentDimensions?.active || {})};
        for (const [dimensionName, value] of requestedDimensions) {
            const values = this.findPreset(dimensionName, value)?.values;
            if (!Array.isArray(values) || values.length === 0) {
                return null;
            }
            targetDimensions[dimensionName] = values;
        }
        return targetDimensions;
    }

    private findPreset(dimensionName: string, value: string): {label?: string; values?: string[]} | undefined {
        const presets = this.store.getState()?.cr?.contentDimensions?.byName?.[dimensionName]?.presets || {};
        return presets[value] || Object.values(presets).find((preset: any) => preset?.values?.[0] === value) as any;
    }

    private presetLabel(dimensionName: string, value: string): string {
        const label = this.findPreset(dimensionName, value)?.label;
        return label ? this.globalRegistry.get('i18n').translate(label) : value;
    }

    private equalsActiveDimensions(targetDimensions: Record<string, string[]>): boolean {
        const activeDimensions = this.store.getState()?.cr?.contentDimensions?.active || {};
        return Object.keys(targetDimensions).every(dimensionName =>
            JSON.stringify(targetDimensions[dimensionName]) === JSON.stringify(activeDimensions[dimensionName]));
    }

    private async openDocumentReview(documentNodeId?: string, documents?: string[], reviewSessionId?: string): Promise<void> {
        const currentDocumentNodePath = this.store.getState()?.cr?.nodes?.documentNode;
        const currentContext = currentDocumentNodePath?.split('@')[1];
        const workspace = currentContext?.split(';')[0];
        if (!workspace || workspace === 'live' ||
            (documents === undefined && (typeof documentNodeId !== 'string' || !UUID_REGEX.test(documentNodeId)))) {
            this.addFlashMessage('neosidekick-review', 'Open a page in an editing workspace to review its changes.', 'error');
            return;
        }

        // Explicit paths pin the language/other dimensions at link creation time.
        // A plain node path deliberately selects every dimension variant of that page.
        let reviewDocuments: string[] = [];
        if (documents !== undefined) {
            if (!Array.isArray(documents) || documents.length === 0) {
                this.addFlashMessage('neosidekick-review', 'The review link must contain at least one page.', 'error');
                return;
            }
            for (const document of documents) {
                const normalized = this.normalizeReviewDocument(document, workspace);
                if (!normalized) {
                    this.addFlashMessage('neosidekick-review', 'The review link contains an invalid page or dimension selection.', 'error');
                    return;
                }
                reviewDocuments.push(normalized);
            }
            reviewDocuments = [...new Set(reviewDocuments)];
        }

        const reviewUrl = new URL('management/workspaces/show', window.location.href);
        reviewUrl.searchParams.set('moduleArguments[workspace][__identity]', workspace);
        // Without the backend chrome, closing itself when done and reporting what was
        // published or discarded to this window. Older package versions ignore it.
        reviewUrl.searchParams.set('moduleArguments[presentation]', 'popup');
        // Each entry needs its own []: commas can be dimension fallback values,
        // never separators between pages. Never omit the filter or drop invalid entries.
        for (const document of reviewDocuments) {
            reviewUrl.searchParams.append('moduleArguments[documents][]', document);
        }
        // Explicit selections open their final URL immediately. Only legacy UUID
        // links need a placeholder before the asynchronous lookup.
        const reviewWindow = window.open(documents === undefined ? 'about:blank' : reviewUrl.toString(), '_blank', 'popup,width=1200,height=900');
        if (!reviewWindow) {
            this.addFlashMessage('neosidekick-review', 'Allow pop-up windows for this site to open the page review.', 'error');
            return;
        }
        // The opener is kept: the popup is a page of this Neos instance and reports its
        // results to this window through it (see handleReviewPopupMessage).
        this.registerReviewWindow(reviewWindow, reviewSessionId);

        try {
            if (documents === undefined) {
                const nodeTypesRegistry = this.globalRegistry.get('@neos-project/neos-ui-contentrepository');
                const documentRole = nodeTypesRegistry.getRole('document');
                if (!documentRole) {
                    throw new Error('Document node types are not loaded.');
                }
                const documentTypes = new Set([documentRole, ...nodeTypesRegistry.getSubTypesOf(documentRole)]);
                const matchesDocument = (node: any): boolean =>
                    node?.identifier === documentNodeId &&
                    typeof node?.contextPath === 'string' &&
                    node.contextPath.startsWith('/sites/') &&
                    node.contextPath.split('@')[1] === currentContext &&
                    documentTypes.has(node.nodeType);

                let documentNode = Object.values(this.store.getState()?.cr?.nodes?.byContextPath || {}).find(matchesDocument) as any;
                if (!documentNode) {
                    const nodes = await backend.get().q(currentDocumentNodePath).find('#' + documentNodeId).get();
                    documentNode = nodes.find(matchesDocument);
                }
                if (!documentNode) {
                    throw new Error('The page could not be found in the active workspace and language.');
                }
                if (this.store.getState()?.cr?.nodes?.documentNode !== currentDocumentNodePath || reviewWindow.closed) {
                    reviewWindow.close();
                    return;
                }
                reviewUrl.searchParams.append('moduleArguments[documents][]', documentNode.contextPath);
                reviewWindow.location.replace(reviewUrl.toString());
            }
            window.addEventListener('focus', () => this.reloadAfterContentChange(), {once: true});
        } catch (error) {
            reviewWindow.close();
            this.addFlashMessage('neosidekick-review', 'Could not open the page review. Please reload Neos and try again.', 'error', String(error));
        }
    }

    private normalizeReviewDocument(document: unknown, workspace: string): string | null {
        if (typeof document !== 'string') {
            return null;
        }
        const match = /^(\/sites\/[a-z0-9-]+(?:\/[a-z0-9-]+)*)(?:@([a-z0-9-]+);(.+))?$/.exec(document);
        if (!match) {
            return null;
        }
        const [, path, , dimensions] = match;
        if (dimensions === undefined) {
            return path;
        }
        const names = new Set<string>();
        for (const dimension of dimensions.split('&')) {
            const pair = /^([a-zA-Z_]+)=([^=&;@?#\s,]+(?:,[^=&;@?#\s,]+)*)$/.exec(dimension);
            if (!pair || names.has(pair[1])) {
                return null;
            }
            names.add(pair[1]);
        }
        return `${path}@${workspace};${dimensions}`;
    }

    private focusContentNodePath(rawContentNodePath?: string): void {
        const contentNodePath = this.normalizeNodePath(rawContentNodePath, CONTENT_NODE_PATH_PROTOCOL);
        if (!contentNodePath) {
            return;
        }

        const state = this.store.getState();
        const currentDocumentNodePath = state?.cr?.nodes?.documentNode;
        const currentDocumentPathWithoutDimensions = currentDocumentNodePath?.split('@')[0];
        const currentContext = currentDocumentNodePath?.split('@')[1];
        if (!currentDocumentPathWithoutDimensions) {
            return;
        }

        const allNodes = Object.values(state?.cr?.nodes?.byContextPath || {});
        const normalizedIdentifier = contentNodePath.replace(/^\/+/, '');
        const normalizedContextPath = contentNodePath.startsWith('/') ? contentNodePath : '/' + contentNodePath;
        const targetNode = allNodes.find((node: any) => {
            const contextPath = node?.contextPath;
            const identifier = node?.identifier;
            // After a dimension switch the store still holds the previous variants; focusing one
            // of them would make the inspector edit another language than the canvas shows.
            const isWithinCurrentDocument = typeof contextPath === 'string' &&
                (!currentContext || contextPath.split('@')[1] === currentContext) &&
                contextPath.split('@')[0].startsWith(currentDocumentPathWithoutDimensions + '/');
            const contextPathMatches = typeof contextPath === 'string' && contextPath.split('@')[0] === normalizedContextPath;
            const identifierMatches = typeof identifier === 'string' && identifier === normalizedIdentifier;
            return isWithinCurrentDocument && (contextPathMatches || identifierMatches);
        }) as any;

        if (!targetNode?.contextPath) {
            const infoMessage = 'The requested content element cannot be found on the current page. You may have changed the page.';
            this.addFlashMessage('1688158257151', infoMessage, 'info', contentNodePath);
            return;
        }

        this.store.dispatch(actions.CR.Nodes.focus(targetNode.contextPath));
        this.store.dispatch(actions.UI.ContentCanvas.requestScrollIntoView(true));
    }

    private normalizeNodePath(rawNodePath: string | undefined, protocol: string): string | null {
        if (!rawNodePath) {
            return null;
        }

        const trimmed = rawNodePath.trim();
        if (!trimmed) {
            return null;
        }

        const pathWithoutProtocol = trimmed.startsWith(protocol)
            ? trimmed.substring(protocol.length)
            : trimmed;
        const pathWithoutQuery = pathWithoutProtocol.split('?')[0].split('#')[0];
        if (!pathWithoutQuery) {
            return null;
        }

        if (UUID_REGEX.test(pathWithoutQuery)) {
            return pathWithoutQuery;
        }

        return pathWithoutQuery.startsWith('/') ? pathWithoutQuery : '/' + pathWithoutQuery;
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

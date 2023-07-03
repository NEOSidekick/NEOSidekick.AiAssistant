import {SynchronousMetaRegistry} from "@neos-project/neos-ui-extensibility";
import {Node, NodeType} from '@neos-project/neos-ts-interfaces';
import {Store} from 'react-redux'

export const createContentService = (globalRegistry: SynchronousMetaRegistry<any>, store: Store): ContentService => {
    return new ContentService(globalRegistry, store)
}

export class ContentService {
    private globalRegistry: SynchronousMetaRegistry<any>;
    private store: Store;
    constructor(globalRegistry: SynchronousMetaRegistry<any>, store: Store) {
        this.globalRegistry = globalRegistry
        this.store = store
    }

    getGuestFrameDocumentHtml = (): string => {
        const guestFrame = document.getElementsByName('neos-content-main')[0];
        // @ts-ignore
        const guestFrameDocument = guestFrame?.contentDocument;
        return guestFrameDocument?.body?.innerHTML;
    }

    getCurrentDocumentNode = (): Node => {
        const state = this.store.getState()
        const currentDocumentNodePath = state?.cr?.nodes?.documentNode
        return state?.cr?.nodes?.byContextPath[currentDocumentNodePath]
    }

    getCurrentDocumentNodeType = (): NodeType => {
        const currentDocumentNode = this.getCurrentDocumentNode()
        return this.globalRegistry.get('@neos-project/neos-ui-contentrepository').get(currentDocumentNode?.nodeType)
    }

    getCurrentDocumentTargetAudience = (): string => {
        const node = this.getCurrentDocumentNode()
        const targetAudience = this.getCurrentDocumentNodeType()?.options?.sidekick?.targetAudience

        if (targetAudience) {
            return this.processClientEval(targetAudience, node, node)
        }

        return null
    }

    getCurrentDocumentPageBriefing = (): string => {
        const node = this.getCurrentDocumentNode()
        const pageBriefing = this.getCurrentDocumentNodeType()?.options?.sidekick?.pageBriefing

        if (pageBriefing) {
            return this.processClientEval(pageBriefing, node, node)
        }

        return null
    }

    getCurrentDocumentFocusKeyword = (): string => {
        const node = this.getCurrentDocumentNode()
        const focusKeyword = this.getCurrentDocumentNodeType()?.options?.sidekick?.focusKeyword

        if (focusKeyword) {
            return this.processClientEval(focusKeyword, node, node)
        }

        return null
    }

    processClientEval = (value: string, node: Node, parentNode: Node): string => {
        if (typeof value === 'string' && value.startsWith('ClientEval:')) {
            try {
                // eslint-disable-next-line no-new-func
                const evaluateFn = new Function('node,parentNode', 'return ' + value.replace('ClientEval:', ''));
                return evaluateFn(node, parentNode)
            } catch (e) {
                console.warn('An error occurred while trying to evaluate "' + value + '"\n', e);
            }
        }

        return value;
    }
}

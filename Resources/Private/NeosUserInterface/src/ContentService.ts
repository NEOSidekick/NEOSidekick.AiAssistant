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

    getCurrentDocumentParentNode = (): Node => {
        const state = this.store.getState()
        const currentDocumentNode = this.getCurrentDocumentNode()
        return state?.cr?.nodes?.byContextPath[currentDocumentNode.parent]
    }

    getCurrentDocumentNodeType = (): NodeType => {
        const currentDocumentNode = this.getCurrentDocumentNode()
        return this.globalRegistry.get('@neos-project/neos-ui-contentrepository').get(currentDocumentNode?.nodeType)
    }

    getCurrentDocumentTargetAudience = (): string => {
        const targetAudience = this.getCurrentDocumentNodeType()?.options?.sidekick?.targetAudience
        if (targetAudience) {
            return this.processClientEval(targetAudience, this.getCurrentDocumentNode(), this.getCurrentDocumentParentNode())
        }
        return null
    }

    getCurrentDocumentPageBriefing = (): string => {
        const pageBriefing = this.getCurrentDocumentNodeType()?.options?.sidekick?.pageBriefing
        if (pageBriefing) {
            return this.processClientEval(pageBriefing, this.getCurrentDocumentNode(), this.getCurrentDocumentParentNode())
        }
        return null
    }

    getCurrentDocumentFocusKeyword = (): string => {
        const focusKeyword = this.getCurrentDocumentNodeType()?.options?.sidekick?.focusKeyword
        if (focusKeyword) {
            return this.processClientEval(focusKeyword, this.getCurrentDocumentNode(), this.getCurrentDocumentParentNode())
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

    processObjectWithClientEval = (obj: object, node: Node, parentNode: Node): object => {
        Object.keys(obj).forEach(key => {
            const value: any = obj[key]

            if (typeof value === 'string') {
                obj[key] = this.processClientEval(value, node, parentNode)
            }

            if (typeof value === 'object') {
                obj[key] = this.processObjectWithClientEval(value, node, parentNode)
            }

            if (Array.isArray(value)) {
                obj[key] = value.map(itemValue => {
                    return this.processClientEval(itemValue, node, parentNode)
                })
            }
        })

        return obj
    }
}
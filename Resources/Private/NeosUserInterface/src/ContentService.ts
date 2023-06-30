import {SynchronousMetaRegistry} from "@neos-project/neos-ui-extensibility";
import {Store} from 'react-redux'

export const createContentService = (globalRegistry: SynchronousMetaRegistry<any>, store: Store): ContentService => {
    return new ContentService(globalRegistry, store)
}

class ContentService {
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

    getCurrentDocumentNode = (): object => {
        const state = this.store.getState()
        const currentDocumentNodePath = state?.cr?.nodes?.documentNode
        return state?.cr?.nodes?.byContextPath[currentDocumentNodePath]
    }

    getCurrentDocumentNodeType = (): object => {
        const currentDocumentNode = this.getCurrentDocumentNode()
        return this.globalRegistry.get('@neos-project/neos-ui-contentrepository').get(currentDocumentNode?.nodeType)
    }

    getCurrentDocumentPageBriefing = (): string => {
        const node = this.getCurrentDocumentNode()
        const template = this.getCurrentDocumentNodeType().options.sidekick.pageBriefing.replaceAll('\`', '\\`')
        console.log(node, template)
        return eval('`' + template + '`');
    }
}

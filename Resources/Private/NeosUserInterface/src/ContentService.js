export const createContentService = (globalRegistry, store) => {
    return new ContentService(globalRegistry, store)
}

class ContentService {
    constructor(globalRegistry, store) {
        this.globalRegistry = globalRegistry
        this.store = store
    }

    getGuestFrameDocumentHtml = () => {
        const guestFrame = document.getElementsByName('neos-content-main')[0];
        // @ts-ignore
        const guestFrameDocument = guestFrame?.contentDocument;
        return guestFrameDocument?.body?.innerHTML;
    }

    getCurrentDocumentNode = () => {
        const state = this.store.getState()
        const currentDocumentNodePath = state?.cr?.nodes?.documentNode
        return state?.cr?.nodes?.byContextPath[currentDocumentNodePath]
    }

    getCurrentDocumentNodeType = () => {
        const currentDocumentNode = this.getCurrentDocumentNode()
        return this.globalRegistry.get('@neos-project/neos-ui-contentrepository').get(currentDocumentNode?.nodeType)
    }

    getCurrentDocumentPageBriefing = () => {
        const node = this.getCurrentDocumentNode()
        const template = this.getCurrentDocumentNodeType().options.sidekick.pageBriefing.replaceAll('\`', '\\`')
        console.log(node, template)
        return eval('`' + template + '`');
    }
}

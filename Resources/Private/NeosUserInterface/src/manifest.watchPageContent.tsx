// @ts-ignore
import {actionTypes} from "@neos-project/neos-ui-redux-store";
// @ts-ignore
import {takeLatest} from 'redux-saga/effects';
import {AssistantService} from "./Service/AssistantService";
import {ContentService} from "./Service/ContentService";
import {createWatchNodeCreatedSaga} from "./Sagas/NodeCreated";
import {createWatchNodeRemovedSaga} from "./Sagas/NodeRemoved";
import {Store} from "@neos-project/neos-ui";

function delay(timeInMilliseconds: number): Promise<void> {
    return new Promise(resolve => setTimeout(resolve, timeInMilliseconds));
}

export default (globalRegistry: object, store: Store, assistantService: AssistantService, contentService: ContentService) => {
    let requiredChangedEvent = false
    const watchDocumentNodeChange = function * () {
        yield takeLatest([actionTypes.UI.ContentCanvas.SET_SRC, actionTypes.UI.ContentCanvas.RELOAD, actionTypes.CR.Nodes.MERGE], async function * (action) {
            if (action.type === actionTypes.UI.ContentCanvas.SET_SRC) {
                requiredChangedEvent = true;
            }
            yield delay(500)

            const nodeTypesRegistry = globalRegistry.get('@neos-project/neos-ui-contentrepository')
            const state = store.getState();

            const previewUrl = state?.ui?.contentCanvas?.previewUrl
            const currentDocumentNode = contentService.getCurrentDocumentNode()
            const currentDocumentNodePath = currentDocumentNode?.contextPath
            // @ts-ignore
            const relevantNodes = Object.values(state?.cr?.nodes?.byContextPath || {}).filter(node => {
                const documentRole = nodeTypesRegistry.getRole('document');
                if (!documentRole) {
                    throw new Error('Document role is not loaded!');
                }
                const documentSubNodeTypes = nodeTypesRegistry.getSubTypesOf(documentRole);
                // only get nodes that are children of the current document node
                return currentDocumentNodePath &&
                    node.contextPath.indexOf(currentDocumentNodePath.split('@')[0]) === 0 &&
                    (node.contextPath === currentDocumentNodePath || !documentSubNodeTypes.includes(node.nodeType))
            })

            assistantService.sendMessageToIframe({
                version: '1.0',
                eventName: requiredChangedEvent ? 'page-changed' : 'page-updated',
                data: {
                    'url': previewUrl,
                    'title': currentDocumentNode?.properties?.title || contentService.getGuestFrameDocumentTitle(),
                    'content': contentService.getGuestFrameDocumentHtml(),
                    'structuredContent': relevantNodes,
                    'targetAudience': await contentService.getCurrentDocumentTargetAudience(),
                    'pageBriefing': await contentService.getCurrentDocumentPageBriefing(),
                    'focusKeyword': await contentService.getCurrentDocumentFocusKeyword()
                },
            })
            requiredChangedEvent = false;
        });
    }

    const sagasRegistry = globalRegistry.get('sagas')
    sagasRegistry.set('NEOSidekick.AiAssistant/watchDocumentNodeChange', {saga: watchDocumentNodeChange})
    sagasRegistry.set('NEOSidekick.AiAssistant/watchNodeCreated', {saga: createWatchNodeCreatedSaga(globalRegistry, store)})
    sagasRegistry.set('NEOSidekick.AiAssistant/watchNodeRemoved', {saga: createWatchNodeRemovedSaga(globalRegistry, store)})
}

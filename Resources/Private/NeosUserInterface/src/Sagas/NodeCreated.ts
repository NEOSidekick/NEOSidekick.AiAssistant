import {takeEvery} from 'redux-saga/effects';
import {actionTypes, selectors} from '@neos-project/neos-ui-redux-store';
import {ContentService} from '../Service/ContentService'
import {ApiService} from "../Service/ApiService";
import {SynchronousMetaRegistry} from "@neos-project/neos-ui-extensibility";
import {Store} from "react-redux";

export const createWatchNodeCreatedSaga = (globalRegistry: SynchronousMetaRegistry<any>, store: Store) => {
    return function * (){
        yield takeEvery(actionTypes.ServerFeedback.HANDLE_SERVER_FEEDBACK, function * (action) {
            action.payload.feedbackEnvelope.feedbacks.forEach(feedback => {
                if (feedback.type !== 'Neos.Neos.Ui:NodeCreated') {
                    return;
                }

                const createdNodeContextPath = feedback.payload.contextPath
                const createdNodePath = createdNodeContextPath.split('@')[0]

                // Get nodesByContextPath in store
                const state = store.getState()
                const nodesByContextPath = selectors.CR.Nodes.nodesByContextPathSelector(state);

                // Get services
                const nodeTypesRegistry = globalRegistry.get('@neos-project/neos-ui-contentrepository')
                const contentService: ContentService = globalRegistry.get('NEOSidekick.AiAssistant').get('contentService')

                Object.keys(nodesByContextPath).forEach(nodeContextPath => {
                    const nodePath = nodeContextPath.split('@')[0]
                    if (!nodePath.startsWith(createdNodePath)) {
                        return;
                    }

                    const node = nodesByContextPath[nodeContextPath]
                    const parentNode = nodesByContextPath[node.parent]
                    const nodeType = nodeTypesRegistry.get(node.nodeType)

                    Object.keys(nodeType.properties).forEach((propertyName) => {
                        contentService.evaluateNodeTypeConfigurationAndStartGeneration(node, propertyName, nodeType, parentNode, true)
                    })
                })
            })
        })
    }
}

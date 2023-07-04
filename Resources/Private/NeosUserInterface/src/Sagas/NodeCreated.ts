// @ts-ignore
import { takeEvery } from 'redux-saga/effects';
import { actionTypes, selectors } from '@neos-project/neos-ui-redux-store';
import {ContentService} from '../ContentService'
import {AssistantService} from "../Service/AssistantService";
import AiAssistantError from "../AiAssistantError";

export const createWatchNodeCreatedSaga = (globalRegistry, store) => {
    return function * (){
        yield takeEvery(actionTypes.ServerFeedback.HANDLE_SERVER_FEEDBACK, function * (action) {
            action.payload.feedbackEnvelope.feedbacks.forEach(feedback => {
                if (feedback.type !== 'Neos.Neos.Ui:NodeCreated') {
                    return;
                }

                const nodeTypesRegistry = globalRegistry.get('@neos-project/neos-ui-contentrepository')
                const contentService: ContentService = globalRegistry.get('NEOSidekick.AiAssistant').get('contentService')
                const assistantService: AssistantService = globalRegistry.get('NEOSidekick.AiAssistant').get('assistantService')
                const state = store.getState()
                const node = selectors.CR.Nodes.nodesByContextPathSelector(state)[feedback.payload.contextPath]
                const parentNode = selectors.CR.Nodes.nodesByContextPathSelector(state)[node.parent]
                const nodeType = nodeTypesRegistry.get(node.nodeType)

                Object.keys(nodeType.properties).forEach((propertyName) => {
                    if (propertyName[0] === '_') {
                        return;
                    }

                    const propertyConfiguration = nodeType.properties[propertyName]
                    if (!propertyConfiguration?.options?.sidekick?.onCreate) {
                        return;
                    }

                    if (!propertyConfiguration?.ui?.inlineEditable) {
                        throw new AiAssistantError('You can only generate content on inline editable properties', '1688395273728')
                    }

                    const processedData = contentService.processObjectWithClientEval(propertyConfiguration.options.sidekick.onCreate, node, parentNode)
                    const message = {
                        version: '1.0',
                        eventName: 'call-module',
                        data: {
                            'platform': 'neos',
                            'target': {
                                'nodePath': node.contextPath,
                                'propertyName': propertyName
                            },
                            ...processedData
                        }
                    }
                    console.log('Message: ', message)
                    assistantService.sendMessageToIframe(message)
                })
            })
        })
    }
}

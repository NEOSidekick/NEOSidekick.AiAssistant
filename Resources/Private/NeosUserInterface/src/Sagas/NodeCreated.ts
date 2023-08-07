// @ts-ignore
import { takeEvery } from 'redux-saga/effects';
import { actions, actionTypes, selectors } from '@neos-project/neos-ui-redux-store';
import {ContentService} from '../ContentService'
import {AssistantService} from "../Service/AssistantService";
import {ExternalService} from "../ExternalService";
import AiAssistantError from "../AiAssistantError";

export const createWatchNodeCreatedSaga = (globalRegistry, store) => {
    return function * (){
        yield takeEvery(actionTypes.ServerFeedback.HANDLE_SERVER_FEEDBACK, function * (action) {
            action.payload.feedbackEnvelope.feedbacks.forEach(feedback => {
                if (feedback.type !== 'Neos.Neos.Ui:NodeCreated') {
                    return;
                }

                const nodeTypesRegistry = globalRegistry.get('@neos-project/neos-ui-contentrepository')
                const i18nRegistry = globalRegistry.get('i18n')
                const contentService: ContentService = globalRegistry.get('NEOSidekick.AiAssistant').get('contentService')
                const assistantService: AssistantService = globalRegistry.get('NEOSidekick.AiAssistant').get('assistantService')
                const externalService: ExternalService = globalRegistry.get('NEOSidekick.AiAssistant').get('externalService')
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

                    try {
                        if (!propertyConfiguration?.ui?.inlineEditable) {
                            throw new AiAssistantError('You can only generate content on inline editable properties', '1688395273728')
                        }

                        if (!externalService.hasApiKey()) {
                            throw new AiAssistantError('This feature is not available in the free version.', '1688157373215')
                        }
                    } catch (e) {
                        store.dispatch(actions.UI.FlashMessages.add(e?.code ?? e?.message, e?.code ? i18nRegistry.translate('NEOSidekick.AiAssistant:Error:' + e.code) : e?.message, e?.severity ?? 'error'))
                    }

                    const configuration = JSON.parse(JSON.stringify(propertyConfiguration.options.sidekick.onCreate))
                    const processedData = contentService.processObjectWithClientEval(configuration, node, parentNode)
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

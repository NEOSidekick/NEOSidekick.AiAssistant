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

                    const topic = contentService.processClientEval(propertyConfiguration.options.sidekick.onCreate.arguments.topic, node, parentNode)
                    assistantService.sendMessageToIframe({
                        version: '1.0',
                        eventName: 'call-module',
                        data: {
                            'plattform': 'neos',
                            'module': 'paragraph_generator',
                            'arguments': {
                                'topic': topic,
                                // 'writing_style': 'tech_advocate' /*optional writing style */
                            },
                            'target': {
                                /* this is for the platform Neos CMS, what properties you need? */
                                'nodePath': node.contextPath,
                                'propertyName': propertyName
                            }
                            /* Vielleicht mal in Der Zukunft.
                            'configuration': {
                                'formatting': ['strong', 'sub', ...] /*Das einfach GPT als Anweisung zu geben führt zu schlechten Ergebnissen. Aktuell soll der CKEditor sich um die Umformattierung kümmern */
                        }
                    })
                })
            })
        })
    }
}

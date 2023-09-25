// @ts-ignore
import { takeEvery } from 'redux-saga/effects';
import { actions, actionTypes, selectors } from '@neos-project/neos-ui-redux-store';
import {ContentService} from '../ContentService'
import {AssistantService} from "../Service/AssistantService";
import {ExternalService} from "../ExternalService";
import AiAssistantError from "../AiAssistantError";

export const createWatchNodeRemovedSaga = (globalRegistry, store) => {
    return function * (){
        yield takeEvery(actionTypes.CR.Nodes.REMOVE, function * (action) {
            console.log('Payload: ', action)

            const assistantService = globalRegistry.get('NEOSidekick.AiAssistant').get('assistantService')
            if (assistantService.currentlyHandledNodePath === action.payload) {
                assistantService.resetCurrentlyHandledNodePath()
                const message = {
                    version: '1.0',
                    eventName: 'cancel-call-module'
                }
                console.log('Message: ', message)
                assistantService.sendMessageToIframe(message)
            }
        })
    }
}

import {takeEvery} from 'redux-saga/effects';
import {actionTypes} from '@neos-project/neos-ui-redux-store';

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

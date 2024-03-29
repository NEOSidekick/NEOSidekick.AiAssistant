import {takeEvery} from 'redux-saga/effects';
import {actionTypes} from '@neos-project/neos-ui-redux-store';

/*
 * When the currently written node is removed, cancel writing into the node.
 */
export const createWatchNodeRemovedSaga = (globalRegistry, store) => {
    return function * (){
        yield takeEvery(actionTypes.CR.Nodes.REMOVE, function * (action) {
            const contentCanvasService = globalRegistry.get('NEOSidekick.AiAssistant').get('contentCanvasService');
            contentCanvasService.onNodeRemoved(action.payload);
        })
    }
}

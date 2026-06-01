import { takeLatest, call } from 'redux-saga/effects';
import { actionTypes } from '@neos-project/neos-ui-redux-store';
import { ContentTreeService } from '../Service/ContentTreeService';

const delayMs = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

export function createPreloadContentTreeSaga(contentTreeService: ContentTreeService) {
    return function* () {
        yield takeLatest(
            [actionTypes.UI.ContentCanvas.SET_SRC, actionTypes.UI.ContentCanvas.RELOAD],
            function* () {
                yield call(delayMs, 10000);
                try {
                    yield call(() => contentTreeService.ensureAllContentNodesLoaded());
                } catch (e) {
                    console.warn('ContentTreeService: background preload failed', e);
                }
            }
        );
    };
}

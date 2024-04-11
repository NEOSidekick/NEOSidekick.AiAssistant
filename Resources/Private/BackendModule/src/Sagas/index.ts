import { all, fork } from "redux-saga/effects";
import {
    watchAddItem, watchGenerateItem,
    watchPersistOneItem,
    watchSaveAllAndFetchNext,
    watchSetPersisted,
    watchStartModule
} from "./AppSaga";

const rootSaga = function* () {
    yield all([
        fork(watchStartModule),
        fork(watchSaveAllAndFetchNext),
        fork(watchAddItem),
        fork(watchPersistOneItem),
        fork(watchSetPersisted),
        fork(watchGenerateItem)
    ]);
};

export default rootSaga;

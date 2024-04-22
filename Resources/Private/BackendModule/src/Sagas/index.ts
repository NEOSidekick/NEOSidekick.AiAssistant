import { all, fork } from "redux-saga/effects";
import {
    watchAddItem, watchGenerateItem,
    watchPersistOneItem,
    watchSaveAllAndFetchNext,
    watchSetItemState,
} from "./AppSaga";

const rootSaga = function* () {
    yield all([
        fork(watchSaveAllAndFetchNext),
        fork(watchAddItem),
        fork(watchPersistOneItem),
        fork(watchSetItemState),
        fork(watchGenerateItem)
    ]);
};

export default rootSaga;

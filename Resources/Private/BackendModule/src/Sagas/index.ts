import { all, fork } from "redux-saga/effects";
import {
    watchAddItem, watchGenerateItem,
} from "./AppSaga";

const rootSaga = function* () {
    // yield all([
    //     fork(watchAddItem),
    //     fork(watchGenerateItem)
    // ]);
};

export default rootSaga;

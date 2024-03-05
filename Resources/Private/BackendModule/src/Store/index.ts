import createSagaMiddleware from "@redux-saga/core";
import { configureStore } from "@reduxjs/toolkit";
import AssetsReducer from "./AssetsSlice";
import AppReducer from "./AppSlice"
import rootSaga from "../Sagas";

export default function createStore(preloadedState: object = {}) {
    const sagaMiddleware = createSagaMiddleware()
    // noinspection TypeScriptValidateTypes
    const store = configureStore({
        preloadedState,
        reducer: {
            app: AppReducer
        },
        middleware: () => ([
            sagaMiddleware
        ]),
    })
    sagaMiddleware.run(rootSaga)
    return store
}

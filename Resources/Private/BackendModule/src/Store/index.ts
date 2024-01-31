import { configureStore } from "@reduxjs/toolkit";
import AssetsReducer from "./AssetsSlice";
import AppReducer from "./AppSlice"

export default configureStore({
    reducer: {
        app: AppReducer,
        assets: AssetsReducer
    }
})

import {createSlice, PayloadAction} from "@reduxjs/toolkit";
import {ModuleItem} from "../Model/ModuleItem";
import {StatefulModuleItem} from "../Model/StatefulModuleItem";
import {ListItemState} from "../Enums/ListItemState";
import {AppState} from "../Enums/AppState";
import {ListState} from "../Enums/ListState";

export const AppSlice = createSlice({
    name: 'app',
    initialState: {
        moduleConfiguration: {},
        initialModuleConfiguration: {},
        appState: AppState.Configure,
        errorMessage: null,
        availableNodeTypeFilters: null
    },
    selectors: {
        getModuleConfiguration: (state) => {
            return state.moduleConfiguration
        }
    },
    reducers: {
        startModule: (() => {
            console.log('start module')
        }),
        setAppState: ((state, { payload: appState }: { payload: AppState }) => {
            console.log(appState)
            state.appState = appState
        }),
        setModuleConfiguration: ((state, action) => {
            state.moduleConfiguration = {
                ...state.moduleConfiguration,
                ...action.payload.moduleConfiguration
            }
        }),
        setErrorMessage: ((state, action) => {
            state.hasError = true
            state.errorMessage = action.payload
        })
    },
})

// noinspection JSUnusedGlobalSymbols
export const {
    startModule,
    saveAllAndFetchNext,
    setAppState,
    setListState,
    setModuleConfiguration,
    replaceItems,
    updateItemProperty,
    setItemPersisted,
    setItemState,
    addItem,
    generateItem,
    resetItems,
    persistOneItem,
    setErrorMessage,
    setBackendMessage
} = AppSlice.actions

export const {
    getModuleConfiguration,
} = AppSlice.getSelectors(AppSlice.selectSlice)
export default AppSlice.reducer

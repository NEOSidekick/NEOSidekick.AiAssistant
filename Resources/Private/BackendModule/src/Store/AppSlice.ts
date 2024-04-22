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
        listState: ListState.Loading,
        items: {},
        errorMessage: null,
        backendMessage: null,
        availableNodeTypeFilters: null
    },
    selectors: {
        isAppStarted: (state) => {
            return state.state === AppState.Edit
        },
        getModuleConfiguration: (state) => {
            return state.moduleConfiguration
        },
        getItem: (state, id: string) => {
            return state.items[id]
        },
        getItems: (state) => {
            return state.items
        },
        hasGeneratingItem: (state) => {
            return Object.keys(state.items).reduce((accumulator, key) => {
                const item: StatefulModuleItem = state.items[key];
                return item.state === ListItemState.Generating || accumulator
            }, false)
        },
        hasPersistingItem: (state) => {
            return Object.keys(state.items).reduce((accumulator, key) => {
                const item: StatefulModuleItem = state.items[key];
                return item.state === ListItemState.Persisting || accumulator
            }, false)
        },
        hasItemWithoutPropertyValue: (state) => {
            return Object.keys(state.items).reduce((accumulator, key) => {
                const item: StatefulModuleItem = state.items[key];
                return item.propertyValue === '' || accumulator
            }, false)
        },
        hasUnpersistedItem: (state) => {
            return Object.keys(state.items).reduce((accumulator, key) => {
                const item: StatefulModuleItem = state.items[key];
                return item.state !== ListItemState.Persisted || accumulator
            }, false)
        },
    },
    reducers: {
        startModule: (() => {
            console.log('start module')
        }),
        saveAllAndFetchNext: (() => {}),
        setAppState: ((state, { payload: appState }: { payload: AppState }) => {
            console.log(appState)
            state.appState = appState
        }),
        setListState: ((state, { payload: listState }: { payload: ListState }) => {
            state.listState = listState
        }),
        setModuleConfiguration: ((state, action) => {
            state.moduleConfiguration = {
                ...state.moduleConfiguration,
                ...action.payload.moduleConfiguration
            }
        }),
        addItem: ((state, { payload: item }: PayloadAction<ModuleItem>) => {
            state.items[item.identifier] = {
                ...item,
                state: ListItemState.Initial
            }
        }),
        generateItem: (() => {}),
        resetItems: ((state) => {
            state.items = {}
        }),
        replaceItems: ((state, action) => {
            state.items = {}
            action.payload.forEach((item: ModuleItem) => {
                state.items[item.identifier] = {
                    ...item,
                    state: ListItemState.Initial
                }
            })
        }),
        updateItemProperty: ((state, action) => {
            const {identifier, propertyName, propertyValue} = action.payload
            const item: StatefulModuleItem = state.items[identifier]
            item[propertyName] = propertyValue
        }),
        setItemPersisted: ((state, action) => {
            const item: StatefulModuleItem = state.items[action.payload.identifier]
            if (action.payload.persisted) {
                item.state = ListItemState.Persisted
            }
        }),
        setItemState: ((state, action) => {
            const item: StatefulModuleItem = state.items[action.payload.identifier]
            item.state = action.payload.state
        }),
        persistOneItem: (() => {}),
        setErrorMessage: ((state, action) => {
            state.hasError = true
            state.errorMessage = action.payload
        }),
        setBackendMessage: ((state, action) => {
            state.backendMessage = action.payload
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
    isAppStarted,
    getModuleConfiguration,
    getItem,
    getItems,
    hasGeneratingItem,
    hasPersistingItem,
    hasItemWithoutPropertyValue,
    hasUnpersistedItem
} = AppSlice.getSelectors(AppSlice.selectSlice)
export default AppSlice.reducer

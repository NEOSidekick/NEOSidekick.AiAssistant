import {createSlice, PayloadAction} from "@reduxjs/toolkit";
import {ModuleItem} from "../Model/ModuleItem";
import {StatefulModuleItem} from "../Model/StatefulModuleItem";

export const AppSlice = createSlice({
    name: 'app',
    initialState: {
        moduleConfiguration: {},
        initialModuleConfiguration: {},
        loading: true,
        started: false,
        busy: false,
        items: {},
        hasError: false,
        errorMessage: null,
        backendMessage: null,
        availableNodeTypeFilters: null
    },
    selectors: {
        isAppStarted: (state) => {
            return state.started
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
                return item.generating || accumulator
            }, false)
        },
        hasPersistingItem: (state) => {
            return Object.keys(state.items).reduce((accumulator, key) => {
                const item: StatefulModuleItem = state.items[key];
                return item.persisting || accumulator
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
                return !item.persisted || accumulator
            }, false)
        },
    },
    reducers: {
        startModule: (() => {}),
        saveAllAndFetchNext: (() => {}),
        setStarted: ((state, { payload }) => {
            state.started = payload
        }),
        setLoading: ((state, action) => {
            state.loading = action.payload.loading
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
                persisted: false,
                persisting: false,
                generating: false
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
                    persisted: false,
                    persisting: false,
                    generating: false
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
            item.persisted = action.payload.persisted
        }),
        setItemGenerating: ((state, action) => {
            const item: StatefulModuleItem = state.items[action.payload.identifier]
            item.generating = action.payload.generating
        }),
        setItemPersisting: ((state, action) => {
            const item: StatefulModuleItem = state.items[action.payload.identifier]
            item.persisting = action.payload.persisting
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
    setStarted,
    setLoading,
    setModuleConfiguration,
    replaceItems,
    updateItemProperty,
    setItemPersisted,
    setItemPersisting,
    setItemGenerating,
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

import {createSlice, PayloadAction} from "@reduxjs/toolkit";
import AssetDtoInterface from "../Model/AssetDtoInterface";
import BackendAssetModuleResultDtoInterface from "../Model/BackendAssetModuleResultDtoInterface";
import {string} from "prop-types";

export const AppSlice = createSlice({
    name: 'app',
    initialState: {
        moduleConfiguration: {},
        loading: true,
        started: false,
        busy: false,
        items: {}
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
                const asset: AssetDtoInterface = state.items[key];
                return asset.generating || accumulator
            }, false)
        },
        hasPersistingItem: (state) => {
            return Object.keys(state.items).reduce((accumulator, key) => {
                const asset: AssetDtoInterface = state.items[key];
                return asset.persisting || accumulator
            }, false)
        },
        hasItemWithoutPropertyValue: (state) => {
            return Object.keys(state.items).reduce((accumulator, key) => {
                const asset: AssetDtoInterface = state.items[key];
                return asset.propertyValue === '' || accumulator
            }, false)
        },
        hasUnpersistedItem: (state) => {
            return Object.keys(state.items).reduce((accumulator, key) => {
                const asset: AssetDtoInterface = state.items[key];
                return !asset.persisted || accumulator
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
        addItem: ((state, { payload: item }: PayloadAction<BackendAssetModuleResultDtoInterface>) => {
            state.items[item.assetIdentifier] = {
                ...item,
                persisted: false,
                persisting: false,
                generating: false
            }
        }),
        resetItems: ((state) => {
            state.items = {}
        }),
        replaceItems: ((state, action) => {
            state.items = {}
            action.payload.forEach((asset: AssetDtoInterface) => {
                state.items[asset.assetIdentifier] = {
                    ...asset,
                    persisted: false,
                    persisting: false,
                    generating: false
                }
            })
        }),
        updateItemPropertyValue: ((state, action) => {
            const {identifier, propertyValue} = action.payload
            const asset: AssetDtoInterface = state.items[identifier]
            asset.propertyValue = propertyValue
        }),
        setItemPersisted: ((state, action) => {
            const asset: AssetDtoInterface = state.items[action.payload.identifier]
            asset.persisted = action.payload.persisted
        }),
        setItemGenerating: ((state, action) => {
            const asset: AssetDtoInterface = state.items[action.payload.identifier]
            asset.generating = action.payload.generating
        }),
        setItemPersisting: ((state, action) => {
            const asset: AssetDtoInterface = state.items[action.payload.identifier]
            asset.persisting = action.payload.persisting
        }),
        persistOneItem: ((state, action) => {})
    },
})

export const {
    startModule,
    saveAllAndFetchNext,
    setStarted,
    setLoading,
    setModuleConfiguration,
    replaceItems,
    updateItemPropertyValue,
    setItemPersisted,
    setItemPersisting,
    setItemGenerating,
    addItem,
    resetItems,
    persistOneItem
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

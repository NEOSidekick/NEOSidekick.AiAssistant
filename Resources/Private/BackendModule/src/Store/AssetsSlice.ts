import {createSlice} from "@reduxjs/toolkit";
import AssetDtoInterface from "../Model/AssetDtoInterface";

export const AssetsSlice = createSlice({
    name: 'assets',
    initialState: {
        busy: false,
        items: {}
    },
    selectors: {
        hasGeneratingItem: (state) => {
            return Object.keys(state.items).reduce((accumulator, key) => {
                const asset: AssetDtoInterface = state.items[key];
                return asset.generating || accumulator
            }, false)
        },
        hasItemWithoutPropertyValue: (state) => {
            return Object.keys(state.items).reduce((accumulator, key) => {
                const asset: AssetDtoInterface = state.items[key];
                return asset.propertyValue === '' || accumulator
            }, false)
        }
    },
    reducers: {
        replace: (state, action) => {
            state.busy = true
            state.items = {}
            action.payload.forEach((asset: AssetDtoInterface) => {
                state.items[asset.assetIdentifier] = {
                    ...asset,
                    persisted: false,
                    persisting: false,
                    generating: false
                }
            })
            state.busy = false
        },
        updatePropertyValue: (state, action) => {
            const asset: AssetDtoInterface = state.items[action.payload.identifier]
            asset.propertyValue = action.payload.data
        },
        setPersisted: (state, action) => {
            const asset: AssetDtoInterface = state.items[action.payload.identifier]
            asset.persisted = action.payload.persisted
        },
        setGenerating: (state, action) => {
            const asset: AssetDtoInterface = state.items[action.payload.identifier]
            asset.generating = action.payload.generating
        },
        setPersisting: (state, action) => {
            const asset: AssetDtoInterface = state.items[action.payload.identifier]
            asset.persisting = action.payload.persisting
        }
    }
})

export const {
    replace,
    updatePropertyValue,
    setPersisted,
    setPersisting,
    setGenerating
} = AssetsSlice.actions
export const {
    hasGeneratingItem,
    hasItemWithoutPropertyValue
} = AssetsSlice.getSelectors(AssetsSlice.selectSlice)
export default AssetsSlice.reducer

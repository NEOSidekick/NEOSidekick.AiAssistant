import {createSlice} from "@reduxjs/toolkit";

export const AppSlice = createSlice({
    name: 'app',
    initialState: {
        persisting: false,
        loading: true
    },
    reducers: {
        setPersisting: ((state, action) => {
            state.persisting = action.payload.persisting
        }),
        setLoading: ((state, action) => {
            state.loading = action.payload.loading
        })
    },
    selectors: undefined,
})

export const {
    setPersisting,
    setLoading
} = AppSlice.actions
export default AppSlice.reducer

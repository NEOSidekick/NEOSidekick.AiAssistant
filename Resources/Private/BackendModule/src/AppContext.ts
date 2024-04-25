import React from "react";
import {AppState} from "./Enums/AppState";

export default React.createContext({
    appConfiguration: {},
    appState: AppState.Configure,
    initialAppConfiguration: {},
    scope: null,
    updateAppConfiguration: () => {},
    updateAppState: () => {},
    setError: () => {},
    errorMessage: null,
    overviewUri: null
})

import React from "react";
import {Endpoints} from "./Model/Endpoints";
import Workspaces from "./Model/Workspaces";
import {ModuleConfiguration} from "./Model/ModuleConfiguration";

export interface AppContextType {
    // backend configuration
    endpoints: Endpoints;
    workspaces: Workspaces;

    // filter and actions
    moduleConfiguration: ModuleConfiguration;
    updateModuleConfiguration: (newConfiguration: Partial<ModuleConfiguration>) => void;

    // app state transitions
    setAppStateToError: (errorMessage: string) => void;
    setAppStateToEdit: () => void;

    // internal state
    appState: AppState;
    errorMessage?: string;
}

export enum AppState {
    Configure = 'configure',
    Edit = 'edit',
    Error = 'error'
}

export default React.createContext({errorMessage:'tg'} as AppContextType);

import React from "react";
import {Endpoints} from "./Model/Endpoints";
import Workspaces from "./Model/Workspaces";
import {
    AssetModuleConfiguration,
    DocumentNodeModuleConfiguration,
    ModuleConfiguration
} from "./Model/ModuleConfiguration";

export interface AppContextType {
    // backend configuration
    endpoints: Endpoints;
    workspaces: Workspaces;
    languageDimensionConfiguration: {
        label: string,
        icon: string,
        default: string,
        presets: {
            [preset: string]: {
                label: string,
                values: string[],
                uriSegment: string
            }
        }
    };
    nodeTypes?: object;
    domain: string;

    // filter and actions
    moduleConfiguration: ModuleConfiguration;
    updateModuleConfiguration: (newConfiguration: Partial<DocumentNodeModuleConfiguration | AssetModuleConfiguration>) => void;

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

export default React.createContext({} as AppContextType);

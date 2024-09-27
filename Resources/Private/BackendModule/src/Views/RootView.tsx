import React from "react";
import PureComponent from "../Components/PureComponent";
import ErrorView from "./ErrorView";
import ConfigurationView from "./ConfigurationView/ConfigurationView";
import {Endpoints} from "../Model/Endpoints";
import ListView from "./ListView/ListView";
import {AssetModuleConfiguration, DocumentNodeModuleConfiguration, ModuleConfiguration} from "../Model/ModuleConfiguration";
import AppContext, {AppContextType, AppState} from "../AppContext";
import Workspaces from "../Model/Workspaces";
import NeosBackendService from "../Service/NeosBackendService";

export interface RootViewProps {
    endpoints: Endpoints,
    moduleConfiguration: ModuleConfiguration,
    workspaces: Workspaces,
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
    },
    domain: string
}

export default class RootView extends PureComponent<RootViewProps, AppContextType> {
    constructor(props: RootViewProps) {
        super(props);
        this.state = {
            endpoints: props.endpoints,
            workspaces: props.workspaces,
            languageDimensionConfiguration: props.languageDimensionConfiguration,
            nodeTypes: undefined,
            domain: props.domain,

            moduleConfiguration: props.moduleConfiguration,
            updateModuleConfiguration: (newConfiguration: Partial<DocumentNodeModuleConfiguration | AssetModuleConfiguration>) => this.updateModuleConfiguration(newConfiguration),

            // app state transitions
            setAppStateToError: (errorMessage: string) => this.setAppStateToError(errorMessage),
            setAppStateToEdit: () => this.setAppStateToEdit(),

            // internal state
            appState: AppState.Configure,
            errorMessage: undefined,
        };
        // noinspection JSIgnoredPromiseFromCall
        this.fetchNodeTypeSchema();
    }

    private async fetchNodeTypeSchema() {
        const backend = NeosBackendService.getInstance();
        const nodeTypeSchema: Partial<{ nodeTypes: object }> = await backend.getNodeTypeSchema()
        this.setState({nodeTypes: nodeTypeSchema.nodeTypes});
    }

    private updateModuleConfiguration(newConfiguration: Partial<ModuleConfiguration>) {
        this.setState(state => ({
                ...state,
                moduleConfiguration: {
                    ...state.moduleConfiguration,
                    ...newConfiguration
                }
            })
        )
    }

    private setAppStateToError(errorMessage: string) {
        this.setState({
            appState: AppState.Error,
            errorMessage
        })
    }

    private setAppStateToEdit() {
        if (this.state.appState !== AppState.Configure) {
            throw new Error('Cannot transition to app state "edit" from "' + this.state.appState + '"');
        }
        this.setState({appState: AppState.Edit});
    }

    render() {
        switch (this.state.appState) {
            case AppState.Error:
                const {endpoints} = this.props;
                const {errorMessage} = this.state;
                return <ErrorView message={errorMessage} overviewUri={endpoints.overview}/>;
            case AppState.Configure:
                return <AppContext.Provider value={this.state}><ConfigurationView/></AppContext.Provider>;
            case AppState.Edit:
                return <AppContext.Provider value={this.state}><ListView/></AppContext.Provider>;
        }
    }
}

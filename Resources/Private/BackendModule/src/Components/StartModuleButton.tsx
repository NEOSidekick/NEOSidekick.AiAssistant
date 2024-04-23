import React from "react";
import PureComponent from "./PureComponent";
import {AppState} from "../Enums/AppState";
import AppContext from "../AppContext";

export default class StartModuleButton extends PureComponent {
    static contextType = AppContext

    private startModule() {
        this.context.updateAppState(AppState.Edit)
    }

    render() {
        return <button className={'neos-button neos-button-primary'} onClick={() => this.startModule()}>
            {this.translationService.translate('NEOSidekick.AiAssistant:Module:startModule', 'Start generation')}
        </button>
    }
}

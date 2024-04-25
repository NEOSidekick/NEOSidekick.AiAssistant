import React from "react";
import PureComponent from "../../Components/PureComponent";
import AppContext from "../../AppContext";

export default class StartModuleButton extends PureComponent {
    static contextType = AppContext

    render() {
        return <button className={'neos-button neos-button-primary'} onClick={() =>  this.context.setAppStateToEdit()}>
            {this.translationService.translate('NEOSidekick.AiAssistant:Module:startModule', 'Start generation')}
        </button>
    }
}

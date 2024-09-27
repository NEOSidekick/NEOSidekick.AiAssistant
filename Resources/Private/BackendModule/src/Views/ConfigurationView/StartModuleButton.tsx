import React from "react";
import PureComponent from "../../Components/PureComponent";
import AppContext, {AppContextType} from "../../AppContext";


export interface StartModuleButtonProps {
    label?: string,
    style?: any
}

export default class StartModuleButton extends PureComponent<StartModuleButtonProps> {
    static contextType = AppContext;
    context: AppContextType;

    render() {
        const {label, style} = this.props;
        return <button className={'neos-button neos-button-primary'} style={style} onClick={() =>  this.context.setAppStateToEdit()}>
            {label || this.translationService.translate('NEOSidekick.AiAssistant:Module:startModule', 'Start generation')}
        </button>
    }
}

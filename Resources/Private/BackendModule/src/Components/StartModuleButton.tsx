import React from "react";
import {connect} from "react-redux";
import PropTypes from "prop-types";
import {setAppState} from "../Store/AppSlice";
import PureComponent from "./PureComponent";
import {AppState} from "../Enums/AppState";

@connect(null, (dispatch) => ({
    setAppState: (state) => dispatch(setAppState(state)),
}))
export default class StartModuleButton extends PureComponent {
    static propTypes = {
        setAppState: PropTypes.func
    }

    private startModule() {
        const {setAppState} = this.props;
        setAppState(AppState.Edit)
    }

    render() {
        return <button className={'neos-button neos-button-primary'} onClick={() => this.startModule()}>
            {this.translationService.translate('NEOSidekick.AiAssistant:Module:startModule', 'Start generation')}
        </button>
    }
}

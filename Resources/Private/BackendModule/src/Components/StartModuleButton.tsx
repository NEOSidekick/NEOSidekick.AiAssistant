import React from "react";
import {connect} from "react-redux";
import PropTypes from "prop-types";
import StateInterface from "../Store/StateInterface";
import {startModule} from "../Store/AppSlice";
import PureComponent from "./PureComponent";

@connect((state: StateInterface) => ({
    started: state.app.started,
    hasError: state.app.hasError,
}), (dispatch, ownProps) => ({
    startModule() {
        dispatch(startModule())
    }
}))
export default class StartModuleButton extends PureComponent {
    static propTypes = {
        started: PropTypes.bool,
        startModule: PropTypes.func
    }

    render() {
        const {started, startModule, hasError} = this.props;
        return ((!started && !hasError) ? <button className={'neos-button neos-button-primary'} onClick={startModule}>
            {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:startModule', 'Start generation')}
        </button> : null);
    }
}

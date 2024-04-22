import React from "react";
import PropTypes from "prop-types";
import {connect} from "react-redux";
import PureComponent from "../PureComponent";
import ErrorView from "./ErrorView";
import ConfigurationView from "./ConfigurationView";
import {Endpoints} from "../../Model/Endpoints";
import {AppState} from "../../Enums/AppState";
import ListView from "./ListView";
import {setAppState} from "../../Store/AppSlice";
import StateInterface from "../../Store/StateInterface";

@connect((state: StateInterface) => ({
    appState: state.app.appState
}), (dispatch) => ({
    setAppState: (state) => dispatch(setAppState(state)),
}))
export default class RootView extends PureComponent {
    static propTypes = {
        appState: PropTypes.number,
        endpoints: PropTypes.object.isRequired
    }

    render() {
        const {appState, endpoints}: {appState: AppState, endpoints: Endpoints} = this.props;
        return [
            appState === AppState.Error ? <ErrorView /> : null,
            appState === AppState.Configure ? <ConfigurationView/> : null,
            appState === AppState.Edit ? <ListView overviewUri={endpoints.overview}/> : null
        ]
    }
}

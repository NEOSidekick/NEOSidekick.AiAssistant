import PureComponent from "./PureComponent";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faSpinner} from "@fortawesome/free-solid-svg-icons";
import React from "react";
import {connect} from "react-redux";
import StateInterface from "../Store/StateInterface";
import {
    hasGeneratingItem,
    hasPersistingItem,
    hasItemWithoutPropertyValue,
    saveAllAndFetchNext,
    getItems
} from "../Store/AppSlice";
import PropTypes from "prop-types";

@connect((state: StateInterface) => ({
    isLoading: state.app.loading,
    started: state.app.started,
    hasError: state.app.hasError,
    isGenerating: hasGeneratingItem(state),
    isPersisting: hasPersistingItem(state),
    hasItems: Object.keys(getItems(state) || {}).length > 0,
    hasItemWithoutPropertyValue: hasItemWithoutPropertyValue(state)
}), (dispatch, ownProps) => ({
    saveAllAndFetchNext: () => dispatch(saveAllAndFetchNext())
}))
export default class SubmitAndFetchNextButton extends PureComponent {
    static propTypes = {
        isPersisting: PropTypes.bool,
        isLoading: PropTypes.bool,
        started: PropTypes.bool,
        isGenerating: PropTypes.bool,
        hasItemWithoutPropertyValue: PropTypes.bool,
        saveAllAndFetchNext: PropTypes.func,
        hasItems: PropTypes.bool
    }

    render() {
        const {started, hasItems, hasError, isPersisting, isLoading, isGenerating, hasItemWithoutPropertyValue, saveAllAndFetchNext} = this.props;
        return ((started && hasItems && !hasError) ? <button
                onClick={saveAllAndFetchNext}
                className={'neos-button neos-button-success'}
                disabled={isPersisting || isLoading || isGenerating || hasItemWithoutPropertyValue}>
                {isPersisting ? <FontAwesomeIcon icon={faSpinner} spin={true}/> : null}
                {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:saveAndNextPage', 'Save all and next page')}
            </button> : null);
    }
}

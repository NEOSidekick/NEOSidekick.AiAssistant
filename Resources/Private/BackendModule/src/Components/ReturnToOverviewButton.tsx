import PureComponent from "./PureComponent";
import {connect} from "react-redux";
import StateInterface from "../Store/StateInterface";
import PropTypes from "prop-types";
import React from "react";

@connect((state: StateInterface) => ({
    scope: state.app.scope,
    items: state.app.items,
    loading: state.app.loading,
    started: state.app.started,
    hasError: state.app.hasError,
}))
export default class ReturnToOverviewButton extends PureComponent {
    static propTypes = {
        href: PropTypes.string,
        scope: PropTypes.string,
        items: PropTypes.object,
        started: PropTypes.bool,
        loading: PropTypes.bool,
        hasError: PropTypes.bool
    }

    private show() {
        const {items, loading, hasError} = this.props;
        return (Object.keys(items).length === 0 && !loading) || hasError
    }

    render() {
        const {href} = this.props;
        return (this.show() ? <a className={'neos-button neos-button-secondary'} href={href}>
            {this.translationService.translate('NEOSidekick.AiAssistant:Module:returnToOverview', 'Return to overview')}
        </a> : null);
    }
}

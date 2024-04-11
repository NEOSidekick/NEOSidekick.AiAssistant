import React from "react";
import {connect} from "react-redux";
import PropTypes from "prop-types";
import {AssetListItem} from "./index";
import StateInterface from "../Store/StateInterface";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faSpinner} from "@fortawesome/free-solid-svg-icons";
import PureComponent from "./PureComponent";
import FocusKeywordListItem from "./FocusKeywordListItem";
import {StatefulModuleItem} from "../Model/StatefulModuleItem";

@connect((state: StateInterface) => ({
    scope: state.app.scope,
    items: state.app.items,
    loading: state.app.loading,
    started: state.app.started,
    hasError: state.app.hasError,
}))
export default class ItemsList extends PureComponent {
    static propTypes = {
        scope: PropTypes.string,
        items: PropTypes.object,
        started: PropTypes.bool,
        loading: PropTypes.bool,
        hasError: PropTypes.bool
    }

    itemsAsArray(): StatefulModuleItem[]
    {
        const {items} = this.props;
        return Object.keys(items).map(key => items[key])
    }

    private loadingIndicator()
    {
        const {loading} = this.props;
        return (
            loading ? <span>
                <FontAwesomeIcon icon={faSpinner} spin={true}/>&nbsp;
                {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:loading', 'Loading...')}
            </span> : null
        )
    }

    private emptyListIndicator()
    {
        const {items, loading} = this.props;
        return (
            (Object.keys(items).length === 0 && !loading) ? <span style={{backgroundColor: '#00a338', padding: '12px', fontWeight: 400, fontSize: '14px', lineHeight: 1.4, marginTop: '18px', display: 'inline-block'}}>
                {this.translationService.translate('NEOSidekick.AiAssistant:Module:listEmpty', 'There are no items that match the filter!')}
            </span> : null
        )
    }

    private renderList()
    {
        const items: StatefulModuleItem[] = this.itemsAsArray()
        return (items.map((item: StatefulModuleItem) => this.renderListItem(item)))
    }

    private renderListItem(item: StatefulModuleItem)
    {
        const {scope} = this.props;
        switch(scope) {
            case 'altTextGeneratorModule':
                return <AssetListItem item={item} />;
            case 'focusKeywordGeneratorModule':
                return <FocusKeywordListItem item={item} />;
        }
        return null;
    }

    render() {
        const {started, hasError} = this.props
        return (started && !hasError) ? [
            this.loadingIndicator(),
            this.emptyListIndicator(),
            this.renderList()
        ] : null
    }
}

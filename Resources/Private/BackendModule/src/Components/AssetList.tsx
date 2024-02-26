import React from "react";
import {connect} from "react-redux";
import PropTypes from "prop-types";
import {AssetListItem} from "./index";
import StateInterface from "../Store/StateInterface";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faSpinner} from "@fortawesome/free-solid-svg-icons";
import BackendAssetModuleResultDtoInterface from "../Model/BackendAssetModuleResultDtoInterface";
import PureComponent from "./PureComponent";

@connect((state: StateInterface) => ({
    assets: state.app.items,
    loading: state.app.loading,
    started: state.app.started
}))
export default class AssetList extends PureComponent {
    static propTypes = {
        assets: PropTypes.object,
        started: PropTypes.bool,
        loading: PropTypes.bool
    }

    assetsAsArray(): BackendAssetModuleResultDtoInterface[]
    {
        const {assets} = this.props;
        return Object.keys(assets).map(key => assets[key])
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
        const {assets, loading} = this.props;
        return (
            (Object.keys(assets).length === 0 && !loading) ? <span style={{backgroundColor: '#00a338', padding: '12px', fontWeight: 400, fontSize: '14px', lineHeight: 1.4}}>
                {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:listEmpty', 'There are no assets with a missing alternative text!')}
            </span> : null
        )
    }

    private renderList()
    {
        const assets: BackendAssetModuleResultDtoInterface[] = this.assetsAsArray()
        return (assets.map((asset: BackendAssetModuleResultDtoInterface) => (
            <AssetListItem asset={asset} />
        )))
    }

    render() {
        const {started} = this.props
        return started ? [
            this.loadingIndicator(),
            this.emptyListIndicator(),
            this.renderList()
        ] : null
    }
}

import React, {PureComponent} from "react";
import {connect} from "react-redux";
import PropTypes from "prop-types";
import {AssetListItem} from "./index";
import BackendAssetResultItemDto from "../Model/BackendAssetResultItemDto";
import StateInterface from "../Store/StateInterface";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faSpinner} from "@fortawesome/free-solid-svg-icons";
import TranslationService from "../Service/TranslationService";

@connect((state: StateInterface) => ({
    assets: state.assets.items,
    loading: state.app.loading
}))
export default class AssetList extends PureComponent {
    static propTypes = {
        assets: PropTypes.object,
        loading: PropTypes.bool
    }
    private translationService: TranslationService;

    constructor(props) {
        super(props)
        this.translationService = TranslationService.getInstance()
    }

    assetsAsArray(): BackendAssetResultItemDto[]
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
            (Object.keys(assets).length === 0 && !loading) ? <span>
                {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:listEmpty', 'There are no assets with a missing alternative text!')}
            </span> : null
        )
    }

    private renderList()
    {
        const assets: BackendAssetResultItemDto[] = this.assetsAsArray()
        return (assets.map((asset: BackendAssetResultItemDto) => (
            <AssetListItem asset={asset} />
        )))
    }

    render() {
        return [
            this.loadingIndicator(),
            this.emptyListIndicator(),
            this.renderList()
        ]
    }
}

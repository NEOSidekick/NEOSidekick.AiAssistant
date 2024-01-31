import React, {PureComponent} from "react";
import PropTypes from "prop-types";
import AssetList from "./AssetList";
import {connect, Provider} from "react-redux";
import {hasGeneratingItem, hasItemWithoutPropertyValue, isBusy, replace} from "../Store/AssetsSlice";
import BackendService from "../Service/BackendService";
import StateInterface from "../Store/StateInterface";
import AssetDtoInterface from "../Model/AssetDtoInterface";
import BackendAssetModuleResultDtoInterface from "../Model/BackendAssetModuleResultDtoInterface";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faSpinner} from "@fortawesome/free-solid-svg-icons";
import {setLoading, setPersisting} from "../Store/AppSlice";
import TranslationService from "../Service/TranslationService";

@connect((state: StateInterface) => ({
    assets: state.assets.items,
    persisting: state.app.persisting,
    loading: state.app.loading,
    isGenerating: hasGeneratingItem(state),
    hasItemWithoutPropertyValue: hasItemWithoutPropertyValue(state)
}), (dispatch, ownProps) => ({
    replaceAssets: (assets: object[]) => dispatch(replace(assets)),
    setPersisting: (persisting: boolean) => dispatch(setPersisting({ persisting })),
    setLoading: (loading: boolean) => dispatch(setLoading({ loading }))
}))
export default class Root extends PureComponent {
    static propTypes = {
        store: PropTypes.object.isRequired,
        assets: PropTypes.array,
        replaceAssets: PropTypes.func,
        persisting: PropTypes.bool,
        setPersisting: PropTypes.func,
        loading: PropTypes.bool,
        setLoading: PropTypes.func,
        isGenerating: PropTypes.bool,
        hasItemWithoutPropertyValue: PropTypes.bool
    }
    private backendService: BackendService;
    private translationService: TranslationService;

    constructor(props) {
        super(props);
        this.backendService = BackendService.getInstance()
        this.translationService = TranslationService.getInstance()
        this.fetchNextPage();
    }

    private fetchNextPage() {
        const {replaceAssets, setLoading} = this.props;
        setLoading(true)
        this.backendService.getAssetsThatNeedProcessing()
            .then(body => {
                console.log(body)
                replaceAssets(body)
                setLoading(false)
            });
    }

    async saveAllAndFetchNext()
    {
        const {assets, setPersisting} = this.props;
        setPersisting(true)
        const assetsWithSetPropertyValue = Object.keys(assets).map((key: string): BackendAssetModuleResultDtoInterface => {
            const asset: AssetDtoInterface = assets[key]
            return {
                assetIdentifier: asset.assetIdentifier,
                filename: asset.filename,
                thumbnailUri: asset.thumbnailUri,
                fullsizeUri: asset.fullsizeUri,
                propertyName: asset.propertyName,
                propertyValue: asset.propertyValue
            }
        }).filter((asset: BackendAssetModuleResultDtoInterface) => {
            return asset.propertyValue.length > 0
        })
        const response = await this.backendService.persistAssets(assetsWithSetPropertyValue)
        console.log(response)
        setPersisting(false)
        this.fetchNextPage()
    }

    render() {
        const {store, persisting, loading, isGenerating, hasItemWithoutPropertyValue} = this.props;
        return (
            <Provider store={store}>
                <div className={'neos-content neos-indented neos-fluid-container'}>
                    <AssetList />
                    <div className={'neos-footer'}>
                        <button
                            onClick={this.saveAllAndFetchNext.bind(this)}
                            className={'neos-button neos-button-success'}
                            disabled={persisting || loading || isGenerating || hasItemWithoutPropertyValue}>
                            {persisting ? <FontAwesomeIcon icon={faSpinner} spin={true}/> : null}
                            {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:saveAndNextPage', 'Save all and next page')}
                        </button>
                    </div>
                </div>
            </Provider>
        )
    }
}

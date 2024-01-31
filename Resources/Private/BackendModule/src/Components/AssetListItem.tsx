import React, {PureComponent, useId} from "react";
import PropTypes from "prop-types";
import {connect} from "react-redux";
import {setGenerating, setPersisted, setPersisting, updatePropertyValue} from "../Store/AssetsSlice";
import {ExternalService} from "../Service/ExternalService";
import BackendService from "../Service/BackendService";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faSpinner} from "@fortawesome/free-solid-svg-icons"
import TranslationService from "../Service/TranslationService";
import {text} from "@fortawesome/fontawesome-svg-core";
@connect(null, (dispatch, ownProps) => ({
    update(data: object) {
        dispatch(updatePropertyValue({ identifier: ownProps.asset.assetIdentifier, data }))
    },
    setGenerating(generating: boolean) {
        dispatch(setGenerating({ identifier: ownProps.asset.assetIdentifier, generating }))
    },
    setPersisting(persisting: boolean) {
        dispatch(setPersisting({ identifier: ownProps.asset.assetIdentifier, persisting }))
    },
    setPersisted(persisted: boolean = true) {
        dispatch(setPersisted({ identifier: ownProps.asset.assetIdentifier, persisted }))
    }
}))
export default class AssetListItem extends PureComponent {
    static propTypes = {
        asset: PropTypes.object.isRequired,
        update: PropTypes.func,
        setPersisted: PropTypes.func,
        setGenerating: PropTypes.func,
        setPersisting: PropTypes.func
    }
    private readonly translationService: TranslationService;

    constructor(props) {
        super(props);
        this.translationService = TranslationService.getInstance()
    }

    async componentDidMount() {
        const {setGenerating} = this.props
        setGenerating(true)
        await this.generate()
    }

    handleChange(event) {
        const {update} = this.props;
        update(event.target.value)
    }

    discard(): void
    {
        const {update} = this.props;
        update('')
    }

    canDiscard(): boolean
    {
        const {asset} = this.props;
        return asset.propertyValue !== '' && !asset.busy;
    }

    canPersist(): boolean
    {
        const {asset} = this.props;
        if (asset.propertyValue === '') {
            return false;
        }
        return !asset.busy;

    }

    async persist()
    {
        const {asset, setPersisted, setPersisting} = this.props;
        setPersisting(true)
        const backend = BackendService.getInstance()
        const response = await backend.persistAssets([asset])
        console.log(response)
        setPersisting(false)
        setPersisted()
    }

    async generate()
    {
        const {asset, update, setGenerating} = this.props
        setGenerating(true)
        const externalService = ExternalService.getInstance()
        const response = await externalService.generate('alt_tag_generator', 'de', [
            {
                identifier: 'url',
                value: [
                    asset.fullsizeUri,
                    asset.thumbnailUri
                ]
            }
        ])
        if (response) {
            update(response)
        }
        setGenerating(false)
    }

    render() {
        const { asset } = this.props;
        const textfieldId = asset.propertyName + '-' + asset.assetIdentifier
        return (asset.persisted ? null :
            <div className={'neos-row-fluid'} style={{marginBottom: '2rem'}}>
                <div className={'neos-span4'} style={{aspectRatio: '3 / 2', position: 'relative'}}>
                    <img style={{position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', objectFit: 'cover'}} src={asset.thumbnailUri}  alt=""/>
                </div>
                <div className={'neos-span8'}>
                    <h2 style={{marginBottom: '1rem'}}>{this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:assetListItemLabel', 'File »' + asset.filename + '«', {0: asset.filename})}</h2>
                    <div className={'neos-control-group'}>
                        <label className={'neos-control-label'} htmlFor={textfieldId}>{this.translationService.translate('Neos.Media.Browser:Main:field_' + asset.propertyName, asset.propertyName)}</label>
                        <div className={'neos-controls'}>
                            <textarea
                                id={textfieldId}
                                className={'neos-span12'}
                                value={asset.propertyValue}
                                rows={5}
                                onChange={this.handleChange.bind(this)} />
                        </div>
                    </div>
                    <div>
                        <button
                            className={'neos-button neos-button-danger'}
                            style={{marginRight: '8px'}}
                            disabled={!this.canDiscard()}
                            onClick={this.discard.bind(this)}>
                            {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:discard', 'Discard')}
                        </button>
                        <button
                            className={'neos-button neos-button-success'}
                            style={{marginRight: '8px'}}
                            disabled={!this.canPersist()}
                            onClick={this.persist.bind(this)}>
                            {asset.persisting ? <FontAwesomeIcon icon={faSpinner} spin={true}/> : null}
                            {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:save', 'Save')}
                        </button>
                        {asset.generating ? <span>
                            <FontAwesomeIcon icon={faSpinner} spin={true}/> {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:generating', 'Generating...')}
                        </span> : null}
                    </div>
                </div>
            </div>
        )
    }
}

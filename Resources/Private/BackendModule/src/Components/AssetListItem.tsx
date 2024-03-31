import React from "react";
import PropTypes from "prop-types";
import {connect} from "react-redux";
import {ExternalService} from "../Service/ExternalService";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faCheck, faSpinner} from "@fortawesome/free-solid-svg-icons"
import {
    persistOneItem,
    updateItemPropertyValue
} from "../Store/AppSlice";
import PureComponent from "./PureComponent";
@connect(null, (dispatch, ownProps) => ({
    update(propertyValue: string) {
        dispatch(updateItemPropertyValue({ identifier: ownProps.asset.assetIdentifier, propertyValue }))
    },
    persist() {
        dispatch(persistOneItem(ownProps.asset.assetIdentifier))
    }
}))
export default class AssetListItem extends PureComponent {
    static propTypes = {
        asset: PropTypes.object.isRequired,
        update: PropTypes.func,
        persist: PropTypes.func
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

    canChangeValue(): boolean
    {
        const {asset} = this.props;
        return !(asset.persisted || asset.generating || asset.persisting);
    }

    canDiscard(): boolean
    {
        const {asset} = this.props;
        return !(asset.persisted || asset.generating || asset.persisting || asset.propertyValue === '');
    }

    canPersist(): boolean
    {
        const {asset} = this.props;
        return !(asset.persisted || asset.generating || asset.persisting || asset.propertyValue === '');
    }

    async generate()
    {
        const {asset, update, setGenerating} = this.props
        setGenerating(true)
        const externalService = ExternalService.getInstance()
        const response = await externalService.generate('image_alt_text', 'de', [
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

    saveButtonLabel() {
        const { asset } = this.props;
        if (asset.persisting) {
            return (
                <span>
                    <FontAwesomeIcon icon={faSpinner} spin={true}/>
                    &nbsp;
                    {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:persisting', 'Saving...')}
                </span>
            )
        } else if (asset.persisted) {
            return (
                <span>
                    <FontAwesomeIcon icon={faCheck} />
                    &nbsp;
                    {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:persisted', 'Saved')}
                </span>
            )
        } else {
            return (
                <span>
                    {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:persist', 'Save')}
                </span>
            )
        }
    }

    render() {
        const { asset, persist } = this.props;
        const textfieldId = asset.propertyName + '-' + asset.assetIdentifier
        return (
            <div className={'neos-row-fluid'} style={{marginBottom: '2rem', opacity: (asset.persisted ? '0.5' : '1')}}>
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
                                onChange={this.handleChange.bind(this)}
                                disabled={!this.canChangeValue()} />
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
                            onClick={persist}>{this.saveButtonLabel()}
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

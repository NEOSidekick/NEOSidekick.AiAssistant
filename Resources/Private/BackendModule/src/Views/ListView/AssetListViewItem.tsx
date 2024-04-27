import React from "react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faCheck, faSpinner} from "@fortawesome/free-solid-svg-icons"
import PureComponent from "../../Components/PureComponent";
import ListViewItem, {ListItemProps} from "./ListViewItem";
import {AssetListItem} from "../../Model/ListItem";

export interface AssetListViewItemProps extends ListItemProps {
    item: AssetListItem
}

// TODO
export default class AssetListViewItem extends PureComponent<AssetListViewItemProps, {}> {
    handleChange(event) {
        const {updateItem} = this.props;
        updateItem(event.target.value)
    }

    discard(): void
    {
        const {updateItem} = this.props;
        updateItem('')
    }

    canChangeValue(): boolean
    {
        const {item} = this.props;
        return !(item.persisted || item.generating || item.persisting);
    }

    canDiscardAndPersist(): boolean
    {
        const {item} = this.props;
        return !(item.persisted || item.generating || item.persisting || item.propertyValue === '');
    }

    renderSaveButtonLabel() {
        const { item } = this.props;
        if (item.persisting) {
            return (
                <span>
                    <FontAwesomeIcon icon={faSpinner} spin={true}/>
                    &nbsp;
                    {this.translationService.translate('NEOSidekick.AiAssistant:Module:persisting', 'Saving...')}
                </span>
            )
        } else if (item.persisted) {
            return (
                <span>
                    <FontAwesomeIcon icon={faCheck} />
                    &nbsp;
                    {this.translationService.translate('NEOSidekick.AiAssistant:Module:persisted', 'Saved')}
                </span>
            )
        } else {
            return (
                <span>
                    {this.translationService.translate('NEOSidekick.AiAssistant:Module:persist', 'Save')}
                </span>
            )
        }
    }

    render() {
        const { item, persistItem } = this.props;
        const textfieldId = item.propertyName + '-' + item.identifier
        return (
            <div className={'neos-row-fluid'} style={{marginBottom: '2rem', opacity: (item.persisted ? '0.5' : '1')}}>
                <div className={'neos-span4'} style={{aspectRatio: '3 / 2', position: 'relative'}}>
                    <img style={{position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', objectFit: 'cover'}} src={item.thumbnailUri}  alt=""/>
                </div>
                <div className={'neos-span8'}>
                    <h2 style={{marginBottom: '1rem'}}>{this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.ImageAlternativeText:assetListItemLabel', 'File »' + item.filename + '«', {0: item.filename})}</h2>
                    <div className={'neos-control-group'}>
                        <label className={'neos-control-label'} htmlFor={textfieldId}>{this.translationService.translate('Neos.Media.Browser:Main:field_' + item.propertyName, item.propertyName)}</label>
                        <div className={'neos-controls'}>
                            <textarea
                                id={textfieldId}
                                className={'neos-span12'}
                                value={item.propertyValue}
                                rows={5}
                                onChange={(e) => this.handleChange(e)}
                                disabled={!this.canChangeValue()} />
                        </div>
                    </div>
                    <div>
                        <button
                            className={'neos-button neos-button-danger'}
                            style={{marginRight: '8px'}}
                            disabled={!this.canDiscardAndPersist()}
                            onClick={() => this.discard()}>
                            {this.translationService.translate('NEOSidekick.AiAssistant:Module:discard', 'Discard')}
                        </button>
                        <button
                            className={'neos-button neos-button-success'}
                            style={{marginRight: '8px'}}
                            disabled={!this.canDiscardAndPersist()}
                            onClick={persistItem}>{this.renderSaveButtonLabel()}
                        </button>
                        {item.generating ? <span>
                            <FontAwesomeIcon icon={faSpinner} spin={true}/> {this.translationService.translate('NEOSidekick.AiAssistant:Module:generating', 'Generating...')}
                        </span> : null}
                    </div>
                </div>
            </div>
        )
    }
}

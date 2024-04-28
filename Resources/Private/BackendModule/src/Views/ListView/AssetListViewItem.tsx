import React from "react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faCheck, faSpinner} from "@fortawesome/free-solid-svg-icons"
import PureComponent from "../../Components/PureComponent";
import {ListItemProps} from "./ListViewItem";
import {AssetListItem, DocumentNodeListItem, ListItemState} from "../../Model/ListItem";
import {ListItemProperty, ListItemPropertyState} from "../../Model/ListItemProperty";
import ErrorMessage from "../../Components/ErrorMessage";
import {Draft, produce} from "immer";
import {SidekickApiService} from "../../Service/SidekickApiService";

export interface AssetListViewItemProps extends ListItemProps {
    item: AssetListItem
}

export default class AssetListViewItem extends PureComponent<AssetListViewItemProps, {}> {
    constructor(props: AssetListViewItemProps) {
        super(props);
        // noinspection JSIgnoredPromiseFromCall
        this.generate();
    }

    async generate() {
        const {item} = this.props;
        const property = Object.values(item.editableProperties)[0];
        this.updateItemProperty(property.propertyName, property.currentValue, ListItemPropertyState.Generating);
        const sidekickApiService = SidekickApiService.getInstance();
        const response = await sidekickApiService.generate('image_alt_text', 'de', [
            {
                identifier: 'url',
                value: [
                    item.fullsizeUri,
                    item.thumbnailUri
                ]
            }
        ])
        debugger
        if (response) {
            this.updateItemProperty(property.propertyName, response, ListItemPropertyState.AiGenerated);
        }
    }

    handleChange(event: any) {
        const {item} = this.props;
        const property = Object.values(item.editableProperties)[0];
        this.updateItemProperty(property.propertyName, event.target.value, ListItemPropertyState.UserManipulated);
    }

    private updateItemProperty(propertyName: string, propertyValue: any, state: ListItemPropertyState) {
        const {updateItem, item} = this.props;
        updateItem(produce(item, (draft: Draft<DocumentNodeListItem>) => {
            draft.editableProperties[propertyName] = {
                ...draft.editableProperties[propertyName],
                state: (state !== ListItemPropertyState.Generating && draft.editableProperties[propertyName].initialValue === propertyValue) ? ListItemPropertyState.Initial : state,
                currentValue: propertyValue
            } as ListItemProperty;
        }));
    }

    private discard(): void {
        const {updateItem, item} = this.props;
        updateItem(produce(item, (draft: Draft<DocumentNodeListItem>) => {
            draft.editableProperties = Object.keys(draft.editableProperties).reduce((accumulator, propertyName) => {
                accumulator[propertyName] = {
                    ...draft.editableProperties[propertyName],
                    state: ListItemPropertyState.Initial,
                    currentValue: draft.editableProperties[propertyName].initialValue,
                } as ListItemProperty;
                return accumulator;
            }, {});
        }));
    }

    private canChangeValue(): boolean
    {
        const {item} = this.props;
        const property = Object.values(item.editableProperties)[0];
        return item.state === ListItemState.Initial && property.state !== ListItemPropertyState.Generating;
    }

    private canDiscardAndPersist(): boolean {
        const {item} = this.props;
        return item.state === ListItemState.Initial && !!Object.values(item.editableProperties).find(property => property.state === ListItemPropertyState.AiGenerated || property.state === ListItemPropertyState.UserManipulated)
    }

    private renderSaveButtonLabel() {
        const {item} = this.props;
        if (item.state === ListItemState.Persisting) {
            return (
                <span>
                    <FontAwesomeIcon icon={faSpinner} spin={true}/>
                    &nbsp;
                    {this.translationService.translate('NEOSidekick.AiAssistant:Module:persisting', 'Saving...')}
                </span>
            )
        } else if (item.state === ListItemState.Persisted) {
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
        const property = Object.values(item.editableProperties)[0];
        const textfieldId = item.propertyName + '-' + item.identifier;

        const textAreaStyle = property.initialValue === property.currentValue ? {} : {
            boxShadow: '0 0 0 2px #ff8700',
            borderRadius: '3px'
        };

        return (
            <div className={'neos-row-fluid'} style={{marginBottom: '2rem', opacity: (item.state === ListItemState.Persisted ? '0.5' : '1')}}>
                <div className={'neos-span4'} style={{aspectRatio: '3 / 2', position: 'relative'}}>
                    <img style={{position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', objectFit: 'cover'}} src={item.thumbnailUri}  alt=""/>
                </div>
                <div className={'neos-span8'}>
                    <h2 style={{marginBottom: '1rem'}}>{this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.ImageAlternativeText:assetListItemLabel', 'File »' + item.filename + '«', {0: item.filename})}</h2>
                    <div className={'neos-control-group'}>
                        <label className={'neos-control-label'} htmlFor={textfieldId}>{this.translationService.translate('Neos.Media.Browser:Main:field_' + property.propertyName, property.propertyName)}</label>
                        <div className={'neos-controls'}>
                            <textarea
                                id={textfieldId}
                                className={property.initialValue !== property.currentValue ? 'textarea--highlight' : ''}
                                style={Object.assign(textAreaStyle, {width: '100%', padding: '10px 14px'})}
                                value={property.currentValue || ''}
                                rows={3}
                                onChange={(e) => this.handleChange(e)}
                                disabled={!this.canChangeValue()} />
                        </div>
                    </div>
                    {Object.values(item.editableProperties).length > 1 && <ErrorMessage message="Expected only one editable property for an asset" />}
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
                            onClick={() => persistItem(item)}>
                            {this.renderSaveButtonLabel()}
                        </button>
                    </div>
                </div>
            </div>
        )
    }
}

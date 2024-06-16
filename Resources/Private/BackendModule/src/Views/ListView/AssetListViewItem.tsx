import React from "react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faCheck, faSpinner} from "@fortawesome/free-solid-svg-icons"
import PureComponent from "../../Components/PureComponent";
import {ListItemProps} from "./ListViewItem";
import {AssetListItem, DocumentNodeListItem, ListItemState} from "../../Model/ListItem";
import {ListItemProperty, ListItemPropertyState} from "../../Model/ListItemProperty";
import Alert from "../../Components/Alert";
import {Draft, produce} from "immer";
import {SidekickApiService} from "../../Service/SidekickApiService";
import {AssetModuleConfiguration} from "../../Model/ModuleConfiguration";
import AppContext, {AppContextType} from "../../AppContext";

export interface AssetListViewItemProps extends ListItemProps {
    item: AssetListItem
}

export interface AssetListViewItemState {
    errorMessage?: string,
}

export default class AssetListViewItem extends PureComponent<AssetListViewItemProps, AssetListViewItemState> {
    static contextType = AppContext;
    context: AppContextType;

    constructor(props: AssetListViewItemProps) {
        super(props);
        this.state = {};
        // noinspection JSIgnoredPromiseFromCall
        // we need to wait for the context to be set
        setTimeout(() => this.generateValue(), 100);
    }

    private getProperty(): ListItemProperty {
        return Object.values(this.props.item.editableProperties)[0];
    }

    async generateValue() {
        const {item} = this.props;
        const moduleConfiguration = this.context.moduleConfiguration as AssetModuleConfiguration;
        const language = moduleConfiguration.language as unknown as string;
        const property = this.getProperty();
        this.updateItemProperty(property.propertyName, property.currentValue, ListItemPropertyState.Generating);
        const sidekickApiService = SidekickApiService.getInstance();
        try {
            const response = await sidekickApiService.generate('image_alt_text', language, [
                {
                    identifier: 'url',
                    value: [
                        this.prependConfiguredDomainToImageUri(item.fullsizeUri),
                        this.prependConfiguredDomainToImageUri(item.thumbnailUri)
                    ]
                }
            ]);
            this.updateItemProperty(property.propertyName, response, ListItemPropertyState.AiGenerated);
        } catch (e) {
            this.updateItemProperty(property.propertyName, property.currentValue, ListItemPropertyState.Initial);
            this.setState({errorMessage: this.translationService.fromError(e)});
        }
    }

    componentDidUpdate(prevProps: Readonly<AssetListViewItemProps>) {
        // when the user starts typing again, remove the error message
        if (!this.state.errorMessage) {
            return;
        }
        const newProperty = this.props.item.editableProperties[Object.keys(this.props.item.editableProperties)[0]];
        if (newProperty.state === ListItemPropertyState.UserManipulated) {
            this.setState({errorMessage: undefined});
        }
    }

    handleChange(event: any) {
        const property = this.getProperty();
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
        const property = this.getProperty();
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

    private prependConfiguredDomainToImageUri(imageUri: string) {
        // Make sure that the imageUri has a domain prepended
        // Get instance domain from configuration
        const hostWithScheme = this.context.domain;
        // Remove the scheme and split URL into parts
        // noinspection HttpUrlsUsage
        const imageUriParts = imageUri
            .replace('http://', '')
            .replace('https://', '')
            .split('/');
        // If the imageUri started with http:// oder https:// we assume that the first item in the imageUriParts array is the host
        // noinspection HttpUrlsUsage
        if (imageUri.startsWith('http://') || imageUri.startsWith('https://')) {
            // Remove the host
            imageUriParts.shift();
        }
        // Re-join the array to a URL, remove double slashes and return
        const relativePath = imageUriParts.join('/').replace('//', '/');
        // Merge the configured host and the path and return
        return hostWithScheme + relativePath;
    }

    render() {
        const { item, persistItem } = this.props;
        const {errorMessage} = this.state;
        const property = this.getProperty();
        const textfieldId = item.propertyName + '-' + item.identifier;

        let textAreaStyle = {width: '100%', padding: '10px 14px'};
        if (property.initialValue !== property.currentValue) {
            textAreaStyle = Object.assign(textAreaStyle, {
                boxShadow: '0 0 0 2px #ff8700',
                borderRadius: '3px',
            });
        }
        if (item.state === ListItemState.Persisted) {
            textAreaStyle = Object.assign(textAreaStyle, {
                boxShadow: 'none',
                background: 'var(--colors-Success)',
            });
        }

        return (
            <div className={'neos-row-fluid'} style={{marginBottom: '2rem', opacity: (item.state === ListItemState.Persisted ? '0.5' : '1')}}>
                <div className={'neos-span4'} style={{aspectRatio: '3 / 2', position: 'relative'}}>
                    <img style={{position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', objectFit: 'cover'}} src={item.thumbnailUri}  alt=""/>
                </div>
                <div className={'neos-span8'}>
                    <h2 style={{marginBottom: '1rem'}}>{this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.ImageAlternativeText:assetListItemLabel', 'File »' + item.filename + '«', {0: item.filename})}</h2>
                    <div className={'neos-control-group'}>
                        <label className={'neos-control-label'} htmlFor={textfieldId}>{this.translationService.translate('Neos.Media.Browser:Main:field_' + property.propertyName, property.propertyName)}</label>
                        <div className={'neos-controls'} style={{position: 'relative'}}>
                            {property.state == ListItemPropertyState.Generating && <FontAwesomeIcon icon={faSpinner} spin={true} style={{position: 'absolute', inset: '12px'}}/>}
                            <textarea
                                id={textfieldId}
                                className={property.initialValue !== property.currentValue ? 'textarea--highlight' : ''}
                                style={textAreaStyle}
                                value={property.currentValue || ''}
                                rows={3}
                                onChange={(e) => this.handleChange(e)}
                                disabled={!this.canChangeValue()} />
                        </div>
                    </div>
                    {Object.values(item.editableProperties).length > 1 && <Alert type="error" message="Expected only one editable property for an asset" />}
                    {errorMessage && <Alert type="error" message={errorMessage}/>}
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

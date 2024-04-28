import PureComponent from "../../Components/PureComponent";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faCheck, faExternalLinkAlt, faSpinner} from "@fortawesome/free-solid-svg-icons";
import React, {RefObject} from "react";
import {ListItemProperty, ListItemPropertyState} from "../../Model/ListItemProperty";
import {Draft, produce} from "immer";
import {DocumentNodeListItem, ListItemState, ListItem} from "../../Model/ListItem";
import {ListItemProps} from "./ListViewItem";
import DocumentNodeListViewItemProperty from "./DocumentNodeListViewItemProperty";
import NeosBackendService from "../../Service/NeosBackendService";

export interface DocumentNodeListViewItemProps extends ListItemProps {
    item: DocumentNodeListItem
}
export interface DocumentNodeListItemState {
    htmlContent: string
}

export default class DocumentNodeListViewItem extends PureComponent<DocumentNodeListViewItemProps, DocumentNodeListItemState> {
    private readonly iframeRef: RefObject<any>;

    constructor(props: ListItemProps) {
        super(props);
        this.state = {
            htmlContent: null
        }
        this.iframeRef = React.createRef();
        // noinspection JSIgnoredPromiseFromCall
        this.fetchIframeContent()
    }

    private async fetchIframeContent() {
        const {item} = this.props
        const htmlContent = await NeosBackendService.getInstance().fetchPreviewContent(item.publicUri);
        this.setState({htmlContent});
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

    componentDidUpdate(prevProps: Readonly<ListItemProps>, prevState: Readonly<DocumentNodeListItemState>) {
        if (!prevState.htmlContent && this.state.htmlContent) {
            const iframe = this.iframeRef.current
            iframe.contentWindow.document.open()
            iframe.contentWindow.document.write(this.state.htmlContent)
            iframe.contentWindow.document.close()
        }
    }

    render() {
        const {item, persistItem} = this.props;
        const {htmlContent} = this.state;
        return (
            <div className={'neos-row-fluid'} style={{marginBottom: '2rem', opacity: (item.state === ListItemState.Persisted ? '0.5' : '1')}}>
                <div className={'neos-span8'} style={{position: 'relative', background: '#3f3f3f'}}>
                    {htmlContent ? null : <FontAwesomeIcon icon={faSpinner} spin={true} style={{position: 'absolute', left: 'calc(50% - 14px)', top: 'calc(50% - 14px)', width: '28px', height: '28px'}}/>}
                    <iframe ref={this.iframeRef} src="about:blank" style={{aspectRatio: '3 / 2', width: '100%'}} />
                </div>
                <div className={'neos-span4'}>
                    <h2 style={{marginBottom: '1rem'}}>{this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:listItem.label', 'Page »' + item.properties.title + '«', {0: item.properties.title})}</h2>
                    <p>
                        <a href={item.publicUri} target="_blank">
                            {item.publicUri}&nbsp;
                            <FontAwesomeIcon icon={faExternalLinkAlt} />
                        </a>
                    </p>
                    <br />
                    {Object.values(item.editableProperties).map((property: ListItemProperty) => {
                        return (
                            <DocumentNodeListViewItemProperty
                                item={item}
                                property={property}
                                htmlContent={htmlContent}
                                updateItemProperty={(value: string, state: ListItemPropertyState) => this.updateItemProperty(property.propertyName, value, state)}
                            />
                        )
                    })}
                    <br />
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
                            onClick={() => persistItem(item)}>{this.renderSaveButtonLabel()}
                        </button>
                    </div>
                </div>
            </div>
        )
    }
}
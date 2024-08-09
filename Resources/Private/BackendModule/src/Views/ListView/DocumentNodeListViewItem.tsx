import PureComponent from "../../Components/PureComponent";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faCheck, faExternalLinkAlt, faSpinner} from "@fortawesome/free-solid-svg-icons";
import React, {RefObject} from "react";
import {ListItemProperty, ListItemPropertyState, PropertySchema} from "../../Model/ListItemProperty";
import {Draft, produce} from "immer";
import {DocumentNodeListItem, ListItemState} from "../../Model/ListItem";
import {ListItemProps} from "./ListViewItem";
import DocumentNodeListViewItemProperty from "./DocumentNodeListViewItemProperty";
import NeosBackendService from "../../Service/NeosBackendService";
import AppContext, {AppContextType} from "../../AppContext";
import DocumentNodeListViewItemImage from "./DocumentNodeListViewItemImage";
import {ListItemImage} from "../../Model/ListItemImage";
import {getItemByIdentifier, ListViewState} from "./ListView";

export interface DocumentNodeListViewItemProps extends ListItemProps {
    item: DocumentNodeListItem
}
export interface DocumentNodeListItemState {
    htmlContent: string
}

export default class DocumentNodeListViewItem extends PureComponent<DocumentNodeListViewItemProps, DocumentNodeListItemState> {
    static contextType = AppContext;
    context: AppContextType;
    private readonly iframeRef: RefObject<any>;

    constructor(props: ListItemProps) {
        super(props);
        this.state = {
            htmlContent: null
        }
        this.iframeRef = React.createRef();
        // noinspection JSIgnoredPromiseFromCall
        setTimeout(() => this.fetchDocumentHtmlContent(), 100);
    }

    private async fetchDocumentHtmlContent() {
        const {item} = this.props;
        if (!item.previewUri) {
            this.setState({htmlContent: '<h1 style="color: white; padding: 1rem;">This the page is not accessible.</h1>'});
            return;
        }
        const htmlContent = await NeosBackendService.getInstance().fetchDocumentHtmlContent(item.previewUri);
        this.setState({htmlContent});
    }

    private updateItemProperty(
        propertyName: string,
        propertyValue: any,
        propertyState: ListItemPropertyState

    ) {
        const {updateItem} = this.props;
        const documentNodeIdentifier = this.props.item.identifier;
        updateItem((state: Readonly<ListViewState>) => {
            const item = getItemByIdentifier(state, documentNodeIdentifier);
            return produce(item, (draft: Draft<DocumentNodeListItem>) => {
                draft.editableProperties[propertyName] = {
                    ...draft.editableProperties[propertyName],
                    state: (propertyState !== ListItemPropertyState.Generating && draft.editableProperties[propertyName].initialValue === propertyValue) ? ListItemPropertyState.Initial : propertyState,
                    currentValue: propertyValue
                } as ListItemProperty;
            })
        });
    }

    private updateItemImageProperty(
        nodeWithImageContextPath: string,
        nodeWithImagePropertyName: string,
        nodeWithImageNewPropertyValue: any,
        nodeWithImageNewPropertyState: ListItemPropertyState
    ) {
        const {updateItem} = this.props;
        const documentNodeIdentifier = this.props.item.identifier;
        updateItem((state: Readonly<ListViewState>) => {
            const item = getItemByIdentifier(state, documentNodeIdentifier);
            return produce(item, (draft: Draft<DocumentNodeListItem>) => {
                draft.images[nodeWithImageContextPath][nodeWithImagePropertyName] = {
                    ...draft.images[nodeWithImageContextPath][nodeWithImagePropertyName],
                    state: (nodeWithImageNewPropertyState !== ListItemPropertyState.Generating && draft.images[nodeWithImageContextPath][nodeWithImagePropertyName].initialValue === nodeWithImageNewPropertyValue) ? ListItemPropertyState.Initial : nodeWithImageNewPropertyState,
                    currentValue: nodeWithImageNewPropertyValue
                } as ListItemProperty;
            });
        });
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
            draft.images = Object.keys(draft.images).reduce((accumulator, contextPath) => {
                let image = {
                    ...draft.images[contextPath]
                } as ListItemImage;
                if (image.alternativeTextProperty) {
                    image.alternativeTextProperty.state = ListItemPropertyState.Initial;
                    image.alternativeTextProperty.currentValue = image.alternativeTextProperty.initialValue;
                }
                if (image.titleTextProperty) {
                    image.titleTextProperty.state = ListItemPropertyState.Initial;
                    image.titleTextProperty.currentValue = image.titleTextProperty.initialValue;
                }
                accumulator[contextPath] = image;
                return accumulator;
            }, {})
        }));
    }

    private canDiscardAndPersist(): boolean {
        const {item} = this.props;
        return item.state === ListItemState.Initial && (
            !!Object.values(item.editableProperties).find(property => property.state === ListItemPropertyState.AiGenerated || property.state === ListItemPropertyState.UserManipulated)
            || !!Object.values(item.images).find((image: ListItemImage) => image.alternativeTextProperty?.state === ListItemPropertyState.AiGenerated || image.alternativeTextProperty?.state === ListItemPropertyState.UserManipulated || image.titleTextProperty?.state === ListItemPropertyState.AiGenerated || image.titleTextProperty?.state === ListItemPropertyState.UserManipulated))
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
        const propertySchemas: { [key: string]: PropertySchema } = this.context.nodeTypes[item.nodeTypeName]?.properties;

        return (
            <div className={'neos-row-fluid'} style={{marginBottom: '2rem', opacity: (item.state === ListItemState.Persisted ? '0.5' : '1')}}>
                <div className={'neos-span8'} style={{position: 'sticky', top: '50px', background: '#3f3f3f'}}>
                    {htmlContent ? null : <FontAwesomeIcon icon={faSpinner} spin={true} style={{position: 'absolute', left: 'calc(50% - 14px)', top: 'calc(50% - 14px)', width: '28px', height: '28px'}}/>}
                    <iframe ref={this.iframeRef} src="about:blank" style={{aspectRatio: '3 / 2', width: '100%'}} />
                </div>
                <div className={'neos-span4'}>
                    <h2 style={{marginBottom: '1rem'}}>{this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:listItem.label', 'Page »' + item.properties.title + '«', {0: item.properties.title})}</h2>
                    <p>
                        <a href={item.publicUri} target="_blank" style={{overflowWrap: 'break-word'}}>
                            {item.publicUri}&nbsp;
                            <FontAwesomeIcon icon={faExternalLinkAlt}/>
                        </a>
                    </p>
                    <br/>
                    {Object.values(item.readonlyProperties).map((property: ListItemProperty) => {
                        return (
                            <DocumentNodeListViewItemProperty
                                key={property.propertyName}
                                item={item}
                                property={property}
                                readonly={true}
                            />
                        )
                    })}
                    {Object.values(item.editableProperties).map((property: ListItemProperty) => {
                        return (
                            <DocumentNodeListViewItemProperty
                                key={property.propertyName}
                                item={item}
                                property={property}
                                htmlContent={htmlContent}
                                updateItemProperty={(value: string, state: ListItemPropertyState) => this.updateItemProperty(property.propertyName, value, state)}
                            />
                        )
                    })}
                    {Object.values(item.images).map((imageProperty) => {
                        return (
                            <DocumentNodeListViewItemImage
                                item={item}
                                imageProperty={imageProperty}
                                htmlContent={htmlContent}
                                updateItemProperty={(propertyName: string, value: string, state: ListItemPropertyState) => this.updateItemImageProperty(imageProperty.nodeContextPath, propertyName, value, state)}
                            />
                        )
                    })}
                    {this.context.moduleConfiguration.showSeoDirectives && (item.properties.canonicalLink || item.properties.metaRobotsNoindex || item.properties.metaRobotsNofollow) && (
                        <div style={{backgroundColor: 'var(--warning)', marginBottom: '1.5rem', padding: '12px', fontWeight: 400, fontSize: '14px', lineHeight: 1.4}}>
                            <h3>{this.translationService.translate('NEOSidekick.AiAssistant:BackendModule:SeoTitleAndMetaDescription:seoDirectivesLabel', 'SEO Directives')}</h3>
                            {item.properties.canonicalLink && (
                                <p>Canonical: {item.properties.canonicalLink}</p>
                            )}
                            {item.properties.metaRobotsNoindex && (
                                <p>✓ {this.translationService.translate(propertySchemas?.metaRobotsNoindex?.ui?.label, propertySchemas?.metaRobotsNoindex?.ui?.label)}</p>
                            )}
                            {item.properties.metaRobotsNofollow && (
                                <p>✓ {this.translationService.translate(propertySchemas?.metaRobotsNofollow?.ui?.label, propertySchemas?.metaRobotsNofollow?.ui?.label)}</p>
                            )}
                        </div>
                    )}
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

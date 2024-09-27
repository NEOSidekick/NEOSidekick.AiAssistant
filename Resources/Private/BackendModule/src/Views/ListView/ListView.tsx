import React from "react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faCheck, faCheckDouble, faChevronLeft, faSpinner} from "@fortawesome/free-solid-svg-icons";
import NeosBackendService from "../../Service/NeosBackendService";
import {ListState} from "../../Model/ListState";
import {AssetListItem, DocumentNodeListItem, ListItem, ListItemState} from "../../Model/ListItem";
import {ListItemProperty, ListItemPropertyState} from "../../Model/ListItemProperty";
import ListViewItem from "./ListViewItem";
import {Draft, produce} from "immer";
import PureComponent from "../../Components/PureComponent";
import AppContext, {AppContextType} from "../../AppContext";
import {ModuleConfiguration} from "../../Model/ModuleConfiguration";
import {FindAssetData, FindDocumentNodeData, FindImageData} from "../../Dto/ListItemDto";
import Alert from "../../Components/Alert";
import ProgressCircles from "../../Components/ProgressCircles";
import ProgressBar from "../../Components/ProgressBar";
import {ListItemImage} from "../../Model/ListItemImage";
import {has} from "lodash";

export function getItemByIdentifier(state: ListViewState, identifier: string): ListItem|undefined {
    return Object.values(state.items).find(item => item.identifier === identifier);
}

export default class ListView extends PureComponent<ListViewProps, ListViewState> {
    static contextType = AppContext;
    context: AppContextType;

    constructor(props: ListViewProps) {
        super(props)
        this.state = {
            listState: ListState.Loading,
            listStateIsSlowLoading: false,
            currentPage: 1,
            itemsPerPage: 10,
        }

        setTimeout(() => {
            this.setState((state, props) => {
                return {...state, listStateIsSlowLoading: true};
            });
        }, 5000);
    }

    async componentDidMount() {
        this.setState({
            itemsPerPage: this.context.moduleConfiguration.itemsPerPage
        })
        const backend: NeosBackendService = NeosBackendService.getInstance()
        try {
            const items: FindAssetData[] | FindDocumentNodeData[] = await backend.getItems(this.context.moduleConfiguration);
            const processedItems = Object.values(items).reduce<ListItems>((accumulator: ListItems, item: FindAssetData | FindDocumentNodeData) => {
                accumulator.push(this.postprocessListItem(item, this.context.moduleConfiguration));
                return accumulator;
            }, [] as ListItems);
            this.setState({
                items: processedItems,
                listState: (Object.values(processedItems).length > 0 ? ListState.Result : ListState.Empty)
            });
        } catch (e) {
            this.context.setAppStateToError(this.translationService.fromError(e));
        }
    }

    private postprocessListItem(item: FindAssetData | FindDocumentNodeData, moduleConfiguration: ModuleConfiguration): AssetListItem | DocumentNodeListItem {
        // @ts-ignore
        return {
            ...item,
            state: ListItemState.Initial,
            properties: item.properties ?? {},
            readonlyProperties: moduleConfiguration.readonlyProperties?.reduce((accumulator, propertyName) => {
                const propertyValue = item.properties[propertyName];
                accumulator[propertyName] = {
                    propertyName,
                    initialValue: propertyValue,
                    currentValue: propertyValue,
                    state: ListItemPropertyState.Initial,
                } as ListItemProperty;
                return accumulator;
            }, {}) || {},
            // todo i made a fallback here with an empty array
            editableProperties: (moduleConfiguration.editableProperties || [])?.reduce((accumulator, propertyName) => {
                const propertyValue = item.properties[propertyName];
                accumulator[propertyName] = {
                    propertyName,
                    initialValue: propertyValue,
                    currentValue: propertyValue,
                    state: ListItemPropertyState.Initial,
                } as ListItemProperty;
                return accumulator;
            }, {}),
            images: (item.images || []).reduce((accumulator: ListItemImage[], image: FindImageData): ListItemImage[] => {
                const newImage = {
                    nodeTypeName: image.nodeTypeName,
                    nodeContextPath: image.nodeContextPath,
                    nodeContextPathWithProperty: image.nodeContextPath + '#' + image.imagePropertyName,
                    nodeOrderIndex: image.nodeOrderIndex,
                    imagePropertyName: image.imagePropertyName,
                    filename: image.filename,
                    fullsizeUri: this.prependConfiguredDomainToImageUri(image.fullsizeUri),
                    thumbnailUri: this.prependConfiguredDomainToImageUri(image.thumbnailUri)
                } as ListItemImage;
                if (image.alternativeTextPropertyName) {
                    newImage.alternativeTextProperty = {
                        propertyName: image.alternativeTextPropertyName,
                        // todo consider this pattern
                        aliasPropertyName: 'alternativeText',
                        initialValue: image.alternativeTextPropertyValue,
                        currentValue: image.alternativeTextPropertyValue,
                        state: ListItemPropertyState.Initial,
                    } as ListItemProperty;
                }
                if (image.titleTextPropertyName) {
                    newImage.titleTextProperty = {
                        propertyName: image.titleTextPropertyName,
                        // todo consider this pattern
                        aliasPropertyName: 'titleText',
                        initialValue: image.titleTextPropertyValue,
                        currentValue: image.titleTextPropertyValue,
                        state: ListItemPropertyState.Initial,
                    } as ListItemProperty;
                }
                accumulator.push(newImage);
                return accumulator;
            }, []).sort((a: ListItemImage, b: ListItemImage) => {
                // todo this does not necessarily resemble the original order on the page, because nested elements could come first but have a longer node context path
                const aWeighted = a.nodeContextPath.length * 1000 + a.nodeOrderIndex;
                const bWeighted = b.nodeContextPath.length * 1000 + b.nodeOrderIndex;
                return aWeighted - bWeighted;
            })
        };
    }

    private prependConfiguredDomainToImageUri(imageUri: string) {
        // Make sure that the imageUri has a domain prepended
        // Get instance domain from configuration
        const instanceDomain = this.context.domain;
        // Remove the scheme and split URL into parts
        const imageUriParts = imageUri.replace('http://', '').replace('https://', '').split('/');
        // Remove the domain
        imageUriParts.shift();
        // Add the domain from configuration
        imageUriParts.unshift(instanceDomain);
        // Re-join the array to a URL and return
        return imageUriParts.join('/');
    }

    private renderLoadingIndicator() {
        return (
            <span>
                <FontAwesomeIcon icon={faSpinner} spin={true}/>&nbsp;
                {this.translationService.translate('NEOSidekick.AiAssistant:Main:loading', 'Loading...')}
                {this.state.listStateIsSlowLoading && <div style={{marginTop: '2.5rem', maxWidth: '500px'}}><Alert type="info" message={this.translationService.translate('NEOSidekick.AiAssistant:Module:slowLoadingMessage', 'Large websites sometimes take a few seconds to calculate the relevant content. Please be patient.')}/></div>}
            </span>
        )
    }

    private renderEmptyListIndicator() {
        return (
            <span style={{
                backgroundColor: '#00a338',
                padding: '12px',
                fontWeight: 400,
                fontSize: '14px',
                lineHeight: 1.4,
                marginTop: '18px',
                display: 'inline-block',
                width: '100%',
                maxWidth: '80ch',
            }}>
                {this.translationService.translate('NEOSidekick.AiAssistant:Module:listEmpty', 'There are no items that match the filter!')}
            </span>
        )
    }

    private paginatedItems(): ListItem[]
    {
        const offset = (this.state.currentPage - 1) * this.state.itemsPerPage;
        return Object.values(this.state.items).slice(offset, offset + this.state.itemsPerPage);
    }

    private renderList() {
        const paginatedItems = this.paginatedItems();
        const itemsCount = Object.values(this.state.items).length;
        if (paginatedItems.length === 0) {
            if (itemsCount === 1000) {
                return (
                    <Alert type="info" message={this.translationService.translate('NEOSidekick.AiAssistant:Module:listLimitReached', 'This tool can currently process a maximum of 1,000 entries at the same time. All your changes are saved, please start a new search.')}/>
                )
            }
            return (
                <Alert type="info" message={this.translationService.translate('NEOSidekick.AiAssistant:Module:listEndReached', 'Congratulations. You have reached the end of the list. You have gone through {0} entries.', {0: itemsCount})}/>
            )
        }

        const totalPages = Math.ceil(Object.values(this.state.items).length / this.state.itemsPerPage);
        return [
            (totalPages <= 10 ? <ProgressCircles currentPage={this.state.currentPage} totalPages={totalPages} /> : <ProgressBar currentPage={this.state.currentPage} totalPages={totalPages} />),
            paginatedItems.map((item: ListItem) =>
                <ListViewItem
                    key={item.identifier}
                    item={item}
                    updateItem={(newItemProducer: (state: Readonly<ListViewState>) => ListItem) => this.updateItem(newItemProducer)}
                    persistItem={(item: ListItem) => this.persistItems([item])}/>)]
    }

    private updateItem(newItemProducer: (state: Readonly<ListViewState>) => ListItem) {
        this.setState(state => {
            const newItem = newItemProducer(state);
            const index = Object.values(state.items).findIndex(item => item.identifier === newItem.identifier)
            const items = produce(state.items, (draft: Draft<ListItem>) => {
                draft[index] = newItem
            })
            return {...state, items};
        })
    }

    private hasUnsavedChanges(): boolean {
        let hasChanges = false;
        for (const item of this.paginatedItems()) {
            if (item.state === ListItemState.Persisting) {
                return false;
            }

            if (item.state === ListItemState.Initial) {
                hasChanges = hasChanges
                    || !!Object.values(item.editableProperties).find(property => property.state === ListItemPropertyState.AiGenerated || property.state === ListItemPropertyState.UserManipulated)
                    || !!Object.values((item as DocumentNodeListItem)?.images).find((image: ListItemImage) => image.alternativeTextProperty?.state === ListItemPropertyState.AiGenerated || image.alternativeTextProperty?.state === ListItemPropertyState.UserManipulated || image.titleTextProperty?.state === ListItemPropertyState.AiGenerated || image.titleTextProperty?.state === ListItemPropertyState.UserManipulated);
            }
        }
        return hasChanges;
    }

    private showSaveButtonSpinner(): boolean {
        return !!this.paginatedItems().find(item => item.state === ListItemState.Persisting)
    }

    private async saveCurrentItemsAndNextPage() {
        if (this.hasUnsavedChanges()) {
            const paginatedItems = this.paginatedItems();
            await this.persistItems(paginatedItems);
        }
        this.goToNextPage()
    }

    private async persistItems(items: ListItem[]) {
        const itemsToPersist = items.filter(item => item.state === ListItemState.Initial);
        itemsToPersist.forEach(item => {
            this.updateItem((state: Readonly<ListViewState>) => {
                const itemFromState = getItemByIdentifier(state, item.identifier);
                return produce(itemFromState, (draft: ListItem) => {
                    draft.state = ListItemState.Persisting
                })
            })
        });
        await NeosBackendService.getInstance().persistItems(
            itemsToPersist.map(item => {
                const properties = Object.values(item.editableProperties).reduce((accumulator, property) => {
                    if (property.state === ListItemPropertyState.AiGenerated || property.state === ListItemPropertyState.UserManipulated) {
                        accumulator[property.propertyName] = property.currentValue;
                    }
                    return accumulator;
                }, {});

                let images: {
                    [key: string]: ListItemImage;
                };
                if (item.type === 'DocumentNode') {
                    images = Object.values((item as DocumentNodeListItem).images).reduce((accumulator, image: ListItemImage) => {
                        accumulator[image.nodeContextPath] = {};
                        if (image.alternativeTextProperty?.state === ListItemPropertyState.AiGenerated || image.alternativeTextProperty?.state === ListItemPropertyState.UserManipulated) {
                            accumulator[image.nodeContextPath][image.alternativeTextProperty.propertyName] = image.alternativeTextProperty.currentValue;
                        }
                        if (image.titleTextProperty?.state === ListItemPropertyState.AiGenerated || image.titleTextProperty?.state === ListItemPropertyState.UserManipulated) {
                            accumulator[image.nodeContextPath][image.titleTextProperty.propertyName] = image.titleTextProperty.currentValue;
                        }
                        return accumulator;
                    }, {});
                }

                switch (item.type) {
                    case 'Asset':
                        return {
                            identifier: item.identifier,
                            properties,
                        }
                    case 'DocumentNode':
                        return {
                            // @ts-ignore
                            nodeContextPath: item.nodeContextPath,
                            properties,
                            images
                        }
                    default:
                        throw new Error('Unknown item type ' + item.type);
                }
            })
            .filter(item => (Object.keys(item.properties).length > 0 || Object.keys(item.images || {}).length > 0))
        );
        itemsToPersist.forEach(item => {
            this.updateItem((state: Readonly<ListViewState>) => {
                const itemFromState = getItemByIdentifier(state, item.identifier);
                return produce(itemFromState, (draft: ListItem) => {
                    draft.state = ListItemState.Persisted
                });
            });
        })
    }

    private goToNextPage() {
        this.setState(state => ({
            ...state,
            currentPage: this.state.currentPage + 1
        }))
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    render() {
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                {this.state.listState === ListState.Loading ? this.renderLoadingIndicator() : null}
                {this.state.listState === ListState.Empty ? this.renderEmptyListIndicator() : null}
                {this.state.listState === ListState.Result ? this.renderList() : null}
                <div className={'neos-footer'}>
                    <a className={'neos-button neos-button-secondary'} href={this.context.endpoints.overview}>
                        <FontAwesomeIcon icon={faChevronLeft}/>&nbsp;
                        {this.translationService.translate('NEOSidekick.AiAssistant:Module:returnToOverview', 'Return to overview')}
                    </a>
                    {this.state.listState === ListState.Result ? <button
                        onClick={() => this.saveCurrentItemsAndNextPage()}
                        className={'neos-button neos-button-success'}>
                        {this.showSaveButtonSpinner() ? <FontAwesomeIcon icon={faSpinner} spin={true}/> : <FontAwesomeIcon icon={this.hasUnsavedChanges() ? faCheckDouble : faCheck}/>}&nbsp;
                        {this.hasUnsavedChanges() ? this.translationService.translate('NEOSidekick.AiAssistant:Module:saveAndNextPage', 'Save all and next page') : this.translationService.translate('NEOSidekick.AiAssistant:Module:nextPage', 'Next page')}
                    </button> : null}
                </div>
            </div>
        )
    }
}

export interface ListViewProps {}

export interface ListViewState {
    listState: ListState,
    listStateIsSlowLoading: boolean,
    items?: ListItems,
    itemsPerPage?: number,
    currentPage?: number
}

export interface ListItems {
    [key: number]: ListItem
    push: (item: ListItem) => void
}

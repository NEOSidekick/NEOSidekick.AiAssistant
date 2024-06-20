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
import {FindAssetData, FindDocumentNodeData} from "../../Dto/ListItemDto";
import Alert from "../../Components/Alert";
import ProgressBar from "../../Components/ProgressBar";

export default class ListView extends PureComponent<ListViewProps, ListViewState> {
    static contextType = AppContext;
    context: AppContextType;

    constructor(props: ListViewProps) {
        super(props)
        this.state = {
            listState: ListState.Loading,
            currentPage: 1,
            itemsPerPage: 10,
        }
    }

    async componentDidMount() {
        this.setState({
            itemsPerPage: this.context.moduleConfiguration.itemsPerPage
        })
        const backend: NeosBackendService = NeosBackendService.getInstance()
        try {
            const items: FindAssetData[] | FindDocumentNodeData[] = await backend.getItems(this.context.moduleConfiguration);
            const processedItems = items.reduce<ListItems>((accumulator: ListItems, item: FindAssetData | FindDocumentNodeData) => {
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
            editableProperties: moduleConfiguration.editableProperties?.reduce((accumulator, propertyName) => {
                const propertyValue = item.properties[propertyName];
                accumulator[propertyName] = {
                    propertyName,
                    initialValue: propertyValue,
                    currentValue: propertyValue,
                    state: ListItemPropertyState.Initial,
                } as ListItemProperty;
                return accumulator;
            }, {})
        };
    }

    private renderLoadingIndicator() {
        return (
            <span>
                <FontAwesomeIcon icon={faSpinner} spin={true}/>&nbsp;
                {this.translationService.translate('NEOSidekick.AiAssistant:Main:loading', 'Loading...')}
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
        if (paginatedItems.length === 0) {
            const itemsCount = Object.values(this.state.items).length;
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
            <ProgressBar currentPage={this.state.currentPage} totalPages={totalPages} translationService={this.translationService}/>,
            this.paginatedItems().map((item: ListItem) =>
                <ListViewItem
                    key={item.identifier}
                    item={item}
                    updateItem={(newItem) => this.updateItem(newItem)}
                    persistItem={(item: ListItem) => this.persistItems([item])}/>)]
    }

    private updateItem(newItem: ListItem) {
        this.setState(state => {
            const identifier: string = newItem.identifier
            const items = produce(state.items, (draft: Draft<ListItem>) => {
                draft[identifier] = newItem
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
                hasChanges = hasChanges || !!Object.values(item.editableProperties).find(property => property.state === ListItemPropertyState.AiGenerated || property.state === ListItemPropertyState.UserManipulated);
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
            this.updateItem(produce(item, (draft: ListItem) => {
                draft.state = ListItemState.Persisting
            }))
        });
        await NeosBackendService.getInstance().persistItems(
            itemsToPersist.map(item => {
                const properties = Object.values(item.editableProperties).reduce((accumulator, property) => {
                    if (property.state === ListItemPropertyState.AiGenerated || property.state === ListItemPropertyState.UserManipulated) {
                        accumulator[property.propertyName] = property.currentValue;
                    }
                    return accumulator;
                }, {});

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
                        }
                    default:
                        throw new Error('Unknown item type ' + item.type);
                }
            })
            .filter(item => Object.keys(item.properties).length > 0)
        );
        itemsToPersist.forEach(item => {
            this.updateItem(produce(item, (draft: ListItem) => {
                draft.state = ListItemState.Persisted
            }))
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
    items?: ListItems,
    itemsPerPage?: number,
    currentPage?: number
}

export interface ListItems {
    [key: number]: ListItem
    push: (item: ListItem) => void
}

import React from "react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faSpinner} from "@fortawesome/free-solid-svg-icons";
import {StatefulModuleItem} from "../../Model/StatefulModuleItem";
import BackendService from "../../Service/BackendService";
import {ModuleItem} from "../../Model/ModuleItem";
import {ListState} from "../../Enums/ListState";
import {ListItemState} from "../../Enums/ListItemState";
import {PropertyState} from "../../Model/PropertiesCollection";
import ListItem from "../ListItems/ListItem";
import {Draft, produce} from "immer";
import PureComponent from "../PureComponent";
import AppContext from "../../AppContext";
import AiAssistantError from "../../Service/AiAssistantError";

export default class ListView extends PureComponent<ListViewProps, ListViewState> {
    static contextType = AppContext
    constructor(props) {
        super(props)
        this.state = {
            currentState: ListState.Loading
        }
    }

    componentDidMount() {
        this.setState(state => ({
            ...state,
            currentPage: 1,
            itemsPerPage: this.context.appConfiguration.limit
        }))
        // noinspection JSIgnoredPromiseFromCall
        this.fetchItems()
    }

    private async fetchItems() {
        const backend: BackendService = BackendService.getInstance()
        try {
            const items: ModuleItem[] = await backend.getItems(this.context.appConfiguration)
            const processedItems = {};
            for (let item: ModuleItem of items) {
                processedItems[item.identifier] = this.postprocessListItem(item)
            }
            const sortedItems = Object.fromEntries(Object.entries(processedItems).sort())
            this.setState(state => ({...state, items: sortedItems, currentState: (Object.values(processedItems).length > 0 ? ListState.Result : ListState.Empty)}))
        } catch (e) {
            this.context.setError(this.translationService.fromError(e));
        }
    }

    private postprocessListItem(item: object): object {
        return {
            ...item,
            state: ListItemState.Initial,
            // todo remove fallback after asset module is migrated
            properties: Object.keys(item.properties ?? {}).reduce((accumulator, propertyName) => {
                const propertyValue = item.properties[propertyName];
                accumulator[propertyName] = {
                    state: PropertyState.Initial,
                    initialValue: propertyValue,
                    currentValue: propertyValue
                };
                return accumulator;
            }, {})
        };
    }

    private renderLoadingIndicator() {
        return (
            <span>
                <FontAwesomeIcon icon={faSpinner} spin={true}/>&nbsp;
                {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:loading', 'Loading...')}
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
                display: 'inline-block'
            }}>
                {this.translationService.translate('NEOSidekick.AiAssistant:Module:listEmpty', 'There are no items that match the filter!')}
            </span>
        )
    }

    private paginatedItems(): StatefulModuleItem[]
    {
        const offset = (this.state.currentPage - 1) * this.state.itemsPerPage;
        return Object.values(this.state.items).slice(offset, offset + this.state.itemsPerPage);
    }

    private renderList() {
        return (this.paginatedItems().map((item: StatefulModuleItem) =>
            <ListItem
                item={item}
                updateItem={(newItem) => this.updateItem(newItem)}
                persistItem={(item: StatefulModuleItem) => this.persistItems([item])} />))
    }

    private updateItem(newItem: object) {
        this.setState(state => {
            const identifier: string = newItem.identifier
            const items = produce(state.items, (draft: Draft<StatefulModuleItem>) => {
                draft[identifier] = newItem
            })
            return {...state, items};
        })
    }

    private allowSaving(): boolean {
        return !this.paginatedItems().find(item => item.state === ListItemState.Generating || item.state === ListItemState.Persisting)
    }

    private showSaveButtonSpinner(): boolean {
        return !!this.paginatedItems().find(item => item.state === ListItemState.Persisting)
    }

    private async saveCurrentItemsAndNextPage() {
        const paginatedItems = this.paginatedItems();
        await this.persistItems(paginatedItems);
        this.goToNextPage()
    }

    private async persistItems(items: StatefulModuleItem[]) {
        items.forEach(item => {
            this.updateItem(produce(item, (draft: StatefulModuleItem) => {
                draft.state = ListItemState.Persisting
            }))
        })
        await BackendService.getInstance().persistItems(
            items.filter(item => item.state !== ListItemState.Persisted)
                .map(item => ({
                    nodeContextPath: item.nodeContextPath,
                    properties: Object.keys(item.properties).reduce((accumulator, propertyName) => {
                        return {
                            ...accumulator,
                            [propertyName]: item.properties[propertyName].currentValue
                        }
                    }, {})
                })))
        items.forEach(item => {
            this.updateItem(produce(item, (draft: StatefulModuleItem) => {
                draft.state = ListItemState.Persisted
            }))
        })
    }

    private goToNextPage() {
        this.setState(state => ({
            ...state,
            currentPage: this.state.currentPage + 1
        }))
        window.scrollTo(0, 0)
    }
    render() {
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                {this.state.currentState === ListState.Loading ? this.renderLoadingIndicator() : null}
                {this.state.currentState === ListState.Empty ? this.renderEmptyListIndicator() : null}
                {this.state.currentState === ListState.Result ? this.renderList() : null}
                <div className={'neos-footer'}>
                    <a className={'neos-button neos-button-secondary'} href={this.context.overviewUri}>
                        {this.translationService.translate('NEOSidekick.AiAssistant:Module:returnToOverview', 'Return to overview')}
                    </a>
                    {this.state.currentState === ListState.Result ? <button
                        onClick={() => this.saveCurrentItemsAndNextPage()}
                        className={'neos-button neos-button-success'}
                        disabled={!this.allowSaving()}>
                        {this.showSaveButtonSpinner() ? <FontAwesomeIcon icon={faSpinner} spin={true}/> : null}
                        {this.translationService.translate('NEOSidekick.AiAssistant:Module:saveAndNextPage', 'Save all and next page')}
                    </button> : null}
                </div>
            </div>
        )
    }
}

export interface ListViewProps {}

export interface ListViewState {
    currentState: ListState,
    items?: {
        [key: string]: StatefulModuleItem
    },
    itemsPerPage?: number,
    currentPage?: number
}

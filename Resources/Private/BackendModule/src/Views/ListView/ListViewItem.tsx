import React from "react";
import PureComponent from "../../Components/PureComponent";
import AssetListViewItem from "./AssetListViewItem";
import DocumentNodeListViewItem from "./DocumentNodeListViewItem";
import {AssetListItem, DocumentNodeListItem, ListItem} from "../../Model/ListItem";

export default class ListViewItem extends PureComponent<ListItemProps> {
    render() {
        const {item, updateItem, persistItem, lazyGenerate} = this.props;
        switch (item.type) {
            case 'Asset':
                return <AssetListViewItem item={item as AssetListItem} updateItem={updateItem} persistItem={persistItem} lazyGenerate={lazyGenerate}/>
            case 'DocumentNode':
                return <DocumentNodeListViewItem item={item as DocumentNodeListItem} updateItem={updateItem} persistItem={persistItem} lazyGenerate={lazyGenerate}/>
            default:
                throw new Error('Unknown item type ' + item.type);
        }
    }
}

export interface ListItemProps {
    item: ListItem;
    updateItem: Function;
    persistItem: Function;
    lazyGenerate: boolean;
}

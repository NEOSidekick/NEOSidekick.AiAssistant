import PureComponent from "./PureComponent";
import AssetListItem from "./AssetListItem";
import NodeListItem from "./NodeListItem";
import React from "react";
import {StatefulModuleItem} from "../Model/StatefulModuleItem";

export default class ListItem extends PureComponent<ListItemProps> {
    render() {
        const {item, updateItem, persistItem} = this.props;
        switch (item.type) {
            case 'Asset':
                return <AssetListItem item={item} updateItem={updateItem} persistItem={persistItem}/>;
            case 'DocumentNode':
                return <NodeListItem item={item} updateItem={updateItem} persistItem={persistItem}/>
        }
        return null;
    }
}

export interface ListItemProps {
    item: StatefulModuleItem,
    updateItem: Function,
    persistItem: Function
}

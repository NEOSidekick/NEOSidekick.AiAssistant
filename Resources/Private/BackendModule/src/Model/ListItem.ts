import {ListItemProperty} from "./ListItemProperty";
import {ListItemImage} from "./ListItemImage";

export enum ListItemState {
    Initial,
    Persisting,
    Persisted,
}

export interface ListItem {
    type: 'ContentNode' | 'DocumentNode' | 'Asset',
    state: ListItemState,
    identifier: string,
    readonlyProperties: {
        [key: string]: ListItemProperty
    },
    editableProperties: {
        [key: string]: ListItemProperty
    },
}

export interface AssetListItem extends ListItem {
    type: 'Asset',
    filename: string
    fullsizeUri: string
    thumbnailUri: string
    propertyName: string
    propertyValue: string
    properties:  {
        [key: string]: string | number | boolean
    },
}

export interface DocumentNodeListItem extends ListItem {
    type: 'DocumentNode',
    nodeContextPath: string,
    nodeTypeName: string,
    publicUri: string,
    previewUri: string,
    language: string
    properties:  {
        [key: string]: string | number | boolean
    },
    editableProperties: {
        [key: string]: ListItemProperty
    },
    images: {
        [key: string]: ListItemImage
    };
}

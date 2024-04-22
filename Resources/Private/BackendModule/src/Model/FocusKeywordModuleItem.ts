import {ItemState} from "./ItemState";
import {PropertyInterface} from "./PropertiesCollection";

export interface FocusKeywordModuleItem {
    type: string,
    identifier: string,
    nodeContextPath: string,
    publicUri: string,
    pageTitle: string,
    properties: {
        [key: string]: PropertyInterface
    },
    language: string
}

export interface StatefulFocusKeywordModuleItem extends FocusKeywordModuleItem, ItemState {}

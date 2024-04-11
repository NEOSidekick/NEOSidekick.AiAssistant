import {ItemState} from "./ItemState";

export interface FocusKeywordModuleItem {
    identifier: string,
    nodeContextPath: string,
    publicUri: string,
    pageTitle: string,
    focusKeyword: string,
    language: string
}

export interface StatefulFocusKeywordModuleItem extends FocusKeywordModuleItem, ItemState {
    pageContent: string
}

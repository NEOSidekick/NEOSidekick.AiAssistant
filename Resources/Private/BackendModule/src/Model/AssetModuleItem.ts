import {ItemState} from "./ItemState";

export interface AssetModuleItem {
    identifier: string,
    filename: string
    thumbnailUri: string
    fullsizeUri: string
    propertyName: string
    propertyValue: string
}

export interface StatefulAssetModuleItem extends AssetModuleItem, ItemState {}

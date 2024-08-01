import {ListItemProperty} from "./ListItemProperty";

export interface ListItemImage {
    label: string;
    nodeType: string;
    nodeContextPath: string;
    filename: string;
    fullsizeUri: string;
    thumbnailUri: string;
    alternativeTextProperty: ListItemProperty | null;
    titleProperty: ListItemProperty | null;
}

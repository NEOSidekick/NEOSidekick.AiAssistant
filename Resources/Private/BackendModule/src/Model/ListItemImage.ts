import {ListItemProperty} from "./ListItemProperty";

export interface ListItemImage {
    label: string;
    nodeTypeName: string;
    nodeContextPath: string;
    nodeContextPathWithProperty: string;
    nodeOrderIndex: number;
    filename: string;
    fullsizeUri: string;
    thumbnailUri: string;
    imagePropertyName: string;
    alternativeTextProperty: ListItemProperty | null;
    titleTextProperty: ListItemProperty | null;
}

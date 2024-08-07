export interface FindAssetData {
    type: 'Asset'
    identifier: string;
    filename: string;
    thumbnailUri: string;
    fullsizeUri: string;
    properties?:  {
        [key: string]: string | number | boolean
    },
}

export interface FindDocumentNodeData {
    type: 'DocumentNode';
    identifier: string;
    nodeContextPath: string;
    nodeTypeName: string;
    publicUri: string;
    properties?:  {
        [key: string]: string | number | boolean
    };
    images?: FindImageData[];
    language: string;
}

export interface FindImageData {
    nodeTypeName: string;
    nodeContextPath: string;
    filename: string;
    fullsizeUri: string;
    thumbnailUri: string | null;
    imagePropertyName: string;
    alternativeTextPropertyName: string | null;
    alternativeTextPropertyValue: string | null;
    titleTextPropertyName: string | null;
    titleTextPropertyValue: string | null;
}

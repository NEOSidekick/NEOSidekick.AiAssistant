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
    },
    language: string;
}

export interface FindFilter {
}

export interface FindAssetsFilter extends FindFilter {
    onlyAssetsInUse: boolean;
}

export interface FindDocumentNodesFilter extends FindFilter {
    workspace: string;
    propertyFilter: string;
    nodeTypeFilter: string;
}

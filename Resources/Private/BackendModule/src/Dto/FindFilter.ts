import {FocusKeywordModuleMode} from "../Model/ModuleConfiguration";

export interface FindFilter {
}

export interface FindAssetsFilter extends FindFilter {
    onlyAssetsInUse: boolean;
}

export interface FindDocumentNodesFilter extends FindFilter {
    workspace: string;
    mode: FocusKeywordModuleMode;
    nodeTypeFilter: string;
}

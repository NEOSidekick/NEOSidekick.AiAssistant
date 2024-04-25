import {FocusKeywordModuleMode} from "../Model/FocusKeywordModuleConfiguration";

export interface Filters {
}

// matches the PHP AssetModuleConfigurationDto DTO
export interface AssetFilters extends Filters {
    onlyAssetsInUse: boolean;
}

// matches the PHP FocusKeywordFilters DTO
export interface DocumentNodeFilters extends Filters {
    workspace: string;
    mode: FocusKeywordModuleMode;
    nodeTypeFilter: string;
}

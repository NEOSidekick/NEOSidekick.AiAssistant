import {ModuleConfiguration} from "./ModuleConfiguration";

export interface FocusKeywordModuleConfiguration extends ModuleConfiguration {
    workspace: string,
    mode: FocusKeywordModuleMode,
    baseNodeTypeFilter: string|null,
    nodeTypeFilter: string|null,
    generateEmptyFocusKeywords: boolean,
    regenerateExistingFocusKeywords: boolean,
}
export enum FocusKeywordModuleMode {
    'both',
    'only-empty',
    'only-existing',
}

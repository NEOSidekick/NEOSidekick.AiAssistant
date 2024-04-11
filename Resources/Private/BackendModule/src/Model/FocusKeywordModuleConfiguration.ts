export interface FocusKeywordModuleConfiguration {
    workspace: string,
    mode: FocusKeywordModuleMode,
    generateEmptyFocusKeywords: boolean,
    regenerateExistingFocusKeywords: boolean,
    nodeTypeFilter: string|null,
    limit: number,
    firstResult: number
}
export enum FocusKeywordModuleMode {
    'only-empty',
    'only-existing',
    'both'
}

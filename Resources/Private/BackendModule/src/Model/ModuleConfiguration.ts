export interface ModuleConfiguration {
    itemType: 'Asset' | 'DocumentNode';
    enforceConfigs: string[];
    itemsPerPage: number;
    editableProperties: string[];
}

export interface DocumentNodeModuleConfiguration extends ModuleConfiguration {
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


export interface AssetModuleConfiguration extends ModuleConfiguration {
    onlyAssetsInUse: boolean;
    propertyName: 'title' | 'caption';
    language: Language;
}

export enum Language {
    'de',
    'en',
    'fr',
    'it',
    'es'
}

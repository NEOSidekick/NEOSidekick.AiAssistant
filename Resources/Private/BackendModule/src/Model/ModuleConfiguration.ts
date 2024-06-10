export interface ModuleConfiguration {
    itemType: 'Asset' | 'DocumentNode';
    enforceConfigs: string[];
    itemsPerPage: number;
    readonlyProperties: string[];
    editableProperties: string[];
}

export interface DocumentNodeModuleConfiguration extends ModuleConfiguration {
    moduleName: string,
    workspace: string,
    seoPropertiesFilterOptions: string[],
    seoPropertiesFilter: string|null,
    focusKeywordPropertyFilterOptions: string[],
    focusKeywordPropertyFilter: string|null,
    baseNodeTypeFilter: string|null,
    languageDimensionFilter: string|null,
    nodeTypeFilter: string|null,
    actions: {
        [key: string]: {
            active: boolean,
            propertyName: string,
            clientEval: string,
        }
    }
    // TODO This should refactored to include readonlyProperties, editableProperties and custom views
    showSeoDirectives: boolean,
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

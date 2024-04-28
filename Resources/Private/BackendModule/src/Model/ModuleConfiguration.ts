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
    propertyFilterOptions: string[],
    propertyFilter: string|null,
    baseNodeTypeFilter: string|null,
    nodeTypeFilter: string|null,
    actions: {
        [key: string]: {
            value: boolean,
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

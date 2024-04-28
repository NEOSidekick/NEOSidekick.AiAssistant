export interface ModuleConfiguration {
    itemType: 'Asset' | 'DocumentNode';
    enforceConfigs: string[];
    itemsPerPage: number;
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

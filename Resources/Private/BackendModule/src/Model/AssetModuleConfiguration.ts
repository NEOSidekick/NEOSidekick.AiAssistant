export interface AssetModuleConfiguration {
    onlyAssetsInUse: OnlyAssetsInUse
    propertyName: AssetPropertyName
    limit: number,
    language: string
}

export enum OnlyAssetsInUse {
    all = 0,
    onlyInUse = 1
}
export enum AssetPropertyName {
    title = 'title',
    caption = 'caption'
}

export enum Language {
    'de',
    'en',
    'fr',
    'it',
    'es'
}

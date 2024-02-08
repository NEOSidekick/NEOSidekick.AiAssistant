export default interface AssetModuleConfigurationInterface {
    readonly onlyAssetsInUse: OnlyAssetsInUse
    readonly propertyName: AssetPropertyName
    readonly limit: number
}

export enum OnlyAssetsInUse {
    all = 0,
    onlyInUse = 1
}
export enum AssetPropertyName {
    title = 'title',
    caption = 'caption'
}

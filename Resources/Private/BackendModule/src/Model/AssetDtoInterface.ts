import BackendAssetModuleResultDtoInterface from "./BackendAssetModuleResultDtoInterface";

export default interface AssetDtoInterface extends BackendAssetModuleResultDtoInterface {
    persisted: boolean
    persisting: boolean
    generating: boolean
}

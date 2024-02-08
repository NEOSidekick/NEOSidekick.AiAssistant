import AssetDtoInterface from "../Model/AssetDtoInterface";
import AssetModuleConfigurationInterface from "../Model/AssetModuleConfigurationInterface";

export default interface StateInterface {
    app: {
        started: boolean;
        moduleConfiguration: AssetModuleConfigurationInterface,
        loading: boolean,
        persisting: boolean,
        busy: boolean,
        items: AssetDtoInterface[],
    }
}

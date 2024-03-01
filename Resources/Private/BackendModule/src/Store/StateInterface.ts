import AssetDtoInterface from "../Model/AssetDtoInterface";
import AssetModuleConfigurationInterface from "../Model/AssetModuleConfigurationInterface";

export default interface StateInterface {
    app: {
        started: boolean;
        moduleConfiguration: AssetModuleConfigurationInterface,
        // This is needed to keep track of the actual configured
        // module configuration, in contrast to default values
        // and user inputs
        initialModuleConfiguration: AssetModuleConfigurationInterface,
        loading: boolean,
        persisting: boolean,
        busy: boolean,
        items: AssetDtoInterface[],
        hasError: boolean,
        errorMessage: string|null,
        backendMessage: string|null
    }
}

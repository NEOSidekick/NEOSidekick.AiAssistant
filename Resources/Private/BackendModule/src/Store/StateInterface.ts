import {ModuleConfiguration} from "../Model/ModuleConfiguration";
import {StatefulModuleItem} from "../Model/StatefulModuleItem";

export default interface StateInterface {
    app: {
        started: boolean;
        moduleConfiguration: ModuleConfiguration,
        // This is needed to keep track of the actual configured
        // module configuration, in contrast to default values
        // and user inputs
        initialModuleConfiguration: ModuleConfiguration,
        scope: string,
        loading: boolean,
        persisting: boolean,
        busy: boolean,
        items: StatefulModuleItem[],
        hasError: boolean,
        errorMessage: string|null,
        backendMessage: string|null,
        availableNodeTypeFilters?: string[]
    }
}

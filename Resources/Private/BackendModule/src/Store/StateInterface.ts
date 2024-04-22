import {ModuleConfiguration} from "../Model/ModuleConfiguration";
import {StatefulModuleItem} from "../Model/StatefulModuleItem";
import {AppState} from "../Enums/AppState";
import {ListState} from "../Enums/ListState";

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
        appState: AppState,
        listState: ListState
    }
}

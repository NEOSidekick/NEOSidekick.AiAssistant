import React from "react";
import PureComponent from "../../Components/PureComponent";
import {DocumentNodeModuleConfiguration} from "../../Model/ModuleConfiguration";
import AppContext, {AppContextType} from "../../AppContext";
import TranslationService from "../../Service/TranslationService";
import SelectField from "../../Components/Field/SelectField";

interface NodeTypeFilterProps {
    // we take this as a prop just for typing the moduleConfiguration
    moduleConfiguration: DocumentNodeModuleConfiguration
}

export default class NodeTypeFilter extends PureComponent<NodeTypeFilterProps,{}> {
    static contextType = AppContext;
    context: AppContextType;

    render() {
        const {nodeTypes} = this.context;
        let options: {[key: string]: string};
        if (nodeTypes) {
            const translationService = TranslationService.getInstance();
            options = {
                '': this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNodeModule:configuration.nodeTypeFilter.all', 'All page types')
            }
            const baseNodeTypeFilter = this.props.moduleConfiguration?.baseNodeTypeFilter;
            Object.keys(nodeTypes).forEach(nodeType => {
                const nodeTypeDefinition: { superTypes: object, ui: { label: string } } = nodeTypes[nodeType]
                if (!baseNodeTypeFilter || nodeTypeDefinition?.superTypes?.hasOwnProperty(baseNodeTypeFilter)) {
                    options[nodeType] = translationService.translate(nodeTypeDefinition.ui.label, nodeTypeDefinition.ui.label);
                }
            });
        } else {
            options = {
                0: this.translationService.translate('NEOSidekick.AiAssistant:Main:loading', 'Loading...'),
            }
        }

        const moduleConfiguration = this.props.moduleConfiguration;
        return (moduleConfiguration.enforceConfigs.includes('nodeTypeFilter') ? null :
                <SelectField
                    label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNodeModule:configuration.nodeTypeFilter.label', 'Restrict to page type')}
                    value={moduleConfiguration.nodeTypeFilter}
                    onChange={e => this.context.updateModuleConfiguration({nodeTypeFilter: e.target.value})}
                    options={options}
                />
        )
    }
}

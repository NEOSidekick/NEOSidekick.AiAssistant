import React from "react";
import PureComponent from "../../Components/PureComponent";
import {FocusKeywordModuleConfiguration} from "../../Model/FocusKeywordModuleConfiguration";
import AppContext from "../../AppContext";
import BackendService from "../../Service/BackendService";
import TranslationService from "../../Service/TranslationService";
import SelectField from "../../Components/Field/SelectField";

interface NodeTypeFilterProps {
    // we take this as a prop just for typing the moduleConfiguration
    moduleConfiguration: FocusKeywordModuleConfiguration
}

interface NodeTypeFilterState {
    availableNodeTypeFilters: object
}

export default class NodeTypeFilter extends PureComponent<NodeTypeFilterProps,NodeTypeFilterState> {
    static contextType = AppContext

    constructor(props: NodeTypeFilterProps) {
        super(props);
        this.state = {
            availableNodeTypeFilters: {
                0: this.translationService.translate('NEOSidekick.AiAssistant:Main:loading', 'Loading...'),
            }
        }
    }

    async componentDidMount() {
        const backend = BackendService.getInstance();
        const translationService = TranslationService.getInstance();
        const nodeTypeSchema: Partial<{nodeTypes: object}> = await backend.getNodeTypeSchema()
        const availableNodeTypes = nodeTypeSchema.nodeTypes
        const availableNodeTypeFilters = {}
        const baseNodeTypeFilter = this.props.moduleConfiguration?.baseNodeTypeFilter;
        Object.keys(availableNodeTypes).forEach(nodeType => {
            const nodeTypeDefinition: {superTypes: object, ui: {label: string}} = availableNodeTypes[nodeType]
            if (nodeTypeDefinition?.superTypes?.hasOwnProperty(baseNodeTypeFilter)) {
                availableNodeTypeFilters[nodeType] = translationService.translate(nodeTypeDefinition.ui.label, nodeTypeDefinition.ui.label);
            }
        });
        this.setState({availableNodeTypeFilters})
    }

    render() {
        const moduleConfiguration = this.props.moduleConfiguration;
        return (moduleConfiguration.enforceConfigs.includes('nodeTypeFilter') ? null :
                <SelectField
                    label={this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.nodeTypeFilter.label', 'Restrict to page type')}
                    value={moduleConfiguration.nodeTypeFilter}
                    onChange={e => this.context.updateModuleConfiguration({nodeTypeFilter: e.target.value})}
                    options={Object.keys(this.state.availableNodeTypeFilters).reduce((acc, nodeType) => {
                        acc[nodeType] = this.state.availableNodeTypeFilters[nodeType];
                        return acc;
                    }, {
                        null: this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.nodeTypeFilter.all', 'All page types')
                    })}
                />
        )
    }
}

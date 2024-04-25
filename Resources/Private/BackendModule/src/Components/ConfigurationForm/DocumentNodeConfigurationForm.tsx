import PureComponent from "../PureComponent";
import LimitField from "./LimitField";
import React from "react";
import {enumKeys} from "../../Util";
import {FocusKeywordModuleMode} from "../../Model/FocusKeywordModuleConfiguration";
import BackendMessage from "../BackendMessage";
import StartModuleButton from "../StartModuleButton";
import AppContext from "../../AppContext";
import BackendService from "../../Service/BackendService";
import TranslationService from "../../Service/TranslationService";
import SelectField from "./SelectField";
import CheckboxField from "./CheckboxField";

export default class DocumentNodeConfigurationForm extends PureComponent {
    static contextType = AppContext
    private state: {availableNodeTypeFilters: object} = {
        availableNodeTypeFilters: {}
    };

    async componentDidMount() {
        const backend = BackendService.getInstance();
        const translationService = TranslationService.getInstance();
        const nodeTypeSchema: Partial<{nodeTypes: object}> = await backend.getNodeTypeSchema()
        const availableNodeTypes = nodeTypeSchema.nodeTypes
        const availableNodeTypeFilters = {}
        Object.keys(availableNodeTypes).map(nodeType => {
            const nodeTypeDefinition: {superTypes: object, ui: {label: string}} = availableNodeTypes[nodeType]
            if (nodeTypeDefinition?.superTypes?.hasOwnProperty('NEOSidekick.AiAssistant:Mixin.AiPageBriefing')) {
                availableNodeTypeFilters[nodeType] = translationService.translate(nodeTypeDefinition.ui.label, nodeTypeDefinition.ui.label);
            }
        });
        this.setState({availableNodeTypeFilters})
    }

    private renderNodeTypeFilterField() {
        return (this.context.initialAppConfiguration?.nodeTypeFilter == null ?
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.nodeTypeFilter.label', 'Restrict to page type')}
                value={this.context.appConfiguration.nodeTypeFilter}
                onChange={e => this.context.updateAppConfiguration({nodeTypeFilter: e.target.value})}
                options={Object.keys(this.state.availableNodeTypeFilters).reduce((acc, nodeType) => {
                    acc[nodeType] = this.state.availableNodeTypeFilters[nodeType];
                    return acc;
                }, {
                    null: this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.nodeTypeFilter.all', 'All page types')
                })}
            />: null
        )
    }

    private renderWorkspaceField() {
        // todo dont use window, and refactor this.context.initialAppConfiguration?.workspace
        const availableWorkspaces = window['_NEOSIDEKICK_AIASSISTANT_workspaces'];
        return (this.context.initialAppConfiguration?.workspace == null ?
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.workspace.label', 'Workspace')}
                value={this.context.appConfiguration.workspace}
                onChange={e => this.context.updateAppConfiguration({workspace: e.target.value})}
                options={Object.keys(availableWorkspaces).reduce((acc, workspaceIdentifier) => {
                    acc[availableWorkspaces[workspaceIdentifier].name] = availableWorkspaces[workspaceIdentifier].title || availableWorkspaces[workspaceIdentifier].name
                    return acc
                }, {})}
            /> : null
        )
    }

    private renderModeField() {
        return (this.context.initialAppConfiguration?.mode == null ?
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.mode.label', 'Selection of pages')}
                value={this.context.appConfiguration.mode}
                onChange={e => this.context.updateAppConfiguration({mode: e.target.value})}
                options={enumKeys(FocusKeywordModuleMode).reduce((acc, mode) => {
                    acc[mode] = this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.mode.' + mode, mode);
                    return acc;
                }, {})}
            /> : null
        )
    }

    private renderGenerateEmptyFocusKeywordsField()
    {
        return (this.context.initialAppConfiguration?.generateEmptyFocusKeywords == null ?
            <CheckboxField
                label={this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.generateEmptyFocusKeywords.label', 'Generate empty focus keywords automatically')}
                checked={this.context.appConfiguration.generateEmptyFocusKeywords}
                onChange={e => this.context.updateAppConfiguration({generateEmptyFocusKeywords: e.target.checked})}
            /> : null
        )
    }

    private renderRegenerateExistingFocusKeywordsField()
    {
        return (this.context.initialAppConfiguration?.regenerateExistingFocusKeywords == null ?
            <CheckboxField
                label={this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.regenerateExistingFocusKeywords.label', 'Regenerate existing focus keywords automatically')}
                checked={this.context.appConfiguration.regenerateExistingFocusKeywords}
                onChange={e => this.context.updateAppConfiguration({regenerateExistingFocusKeywords: e.target.checked})}
            /> : null
        )
    }

    render() {
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <p style={{marginBottom: '1rem'}} dangerouslySetInnerHTML={{__html: this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:intro', '')}}/>
                <BackendMessage identifier="focus-keyword"/>

                <h2>{this.translationService.translate('NEOSidekick.AiAssistant:Main:selectionFilter', '')}</h2>
                <br/>
                {this.renderWorkspaceField()}
                {this.renderModeField()}
                {this.renderNodeTypeFilterField()}
                <LimitField configuration={this.context.appConfiguration} updateConfiguration={this.context.updateAppConfiguration}/>
                <br/>
                <br/>
                <h2>{this.translationService.translate('NEOSidekick.AiAssistant:Main:actions', '')}</h2>
                <br/>
                {this.renderGenerateEmptyFocusKeywordsField()}
                {this.renderRegenerateExistingFocusKeywordsField()}
                <div className={'neos-footer'}>
                    <StartModuleButton/>
                </div>
            </div>
        )
    }
}

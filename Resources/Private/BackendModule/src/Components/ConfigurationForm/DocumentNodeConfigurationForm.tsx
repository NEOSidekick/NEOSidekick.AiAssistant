import PureComponent from "../PureComponent";
import LimitField from "./LimitField";
import React from "react";
import {enumKeys} from "../../Util";
import {FocusKeywordModuleMode} from "../../Model/FocusKeywordModuleConfiguration";
import BackendMessage from "../BackendMessage";
import StartModuleButton from "../StartModuleButton";
import AppContext from "../../AppContext";

export default class DocumentNodeConfigurationForm extends PureComponent {
    static contextType = AppContext

    private renderNodeTypeFilterField() {
        return (this.context.initialAppConfiguration?.nodeTypeFilter == null ?
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>
                    {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.nodeTypeFilter.label', 'Restrict to page type')}
                </label>
                <div className={'neos-controls'}>
                    <select
                        value={this.context.appConfiguration.nodeTypeFilter}
                        onChange={e => this.context.updateAppConfiguration({nodeTypeFilter: e.target.value})}>
                        <option value={null}>{this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.nodeTypeFilter.all', 'All page types')}</option>
                        {Object.keys(this.context.availableNodeTypeFilters).map(nodeType =>
                            <option value={nodeType}>
                                {this.context.availableNodeTypeFilters[nodeType]}
                            </option>
                        )}
                    </select>
                </div>
            </div> : null
        )
    }

    private renderWorkspaceField() {
        const availableWorkspaces = window['_NEOSIDEKICK_AIASSISTANT_workspaces']
        return (this.context.initialAppConfiguration?.workspace == null ?
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>
                    {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.workspace.label', 'Workspace')}
                </label>
                <div className={'neos-controls'}>
                    <select
                        value={this.context.appConfiguration.workspace}
                        onChange={e => this.context.updateAppConfiguration({workspace: e.target.value})}>
                        {Object.keys(availableWorkspaces).map(workspaceIdentifier =>
                            <option value={availableWorkspaces[workspaceIdentifier].name}>
                                {availableWorkspaces[workspaceIdentifier].title || availableWorkspaces[workspaceIdentifier].name}
                            </option>
                        )}
                    </select>
                </div>
            </div> : null
        )
    }

    private renderModeField() {
        return (this.context.initialAppConfiguration?.mode == null ?
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>
                    {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.mode.label', 'Selection of pages')}
                </label>
                <div className={'neos-controls'}>
                    <select
                        value={this.context.appConfiguration.mode}
                        onChange={e => this.context.updateAppConfiguration({mode: e.target.value})}>
                        {enumKeys(FocusKeywordModuleMode).map(mode =>
                            <option value={mode}>
                                {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.mode.' + mode, mode)}
                            </option>
                        )}
                    </select>
                </div>
            </div> : null
        )
    }

    private renderGenerateEmptyFocusKeywordsField()
    {
        return (this.context.initialAppConfiguration?.generateEmptyFocusKeywords == null ?
            <div className={'neos-control-group'}>
                <label className="neos-checkbox">
                    <input
                        type="checkbox"
                        onChange={e => this.context.updateAppConfiguration({generateEmptyFocusKeywords: e.target.checked})}
                        checked={this.context.appConfiguration.generateEmptyFocusKeywords}
                        value="1" />
                    <span></span>
                    {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.generateEmptyFocusKeywords.label', 'Generate empty focus keywords automatically')}
                </label>
            </div> : null
        )
    }

    private renderRegenerateExistingFocusKeywordsField()
    {
        return (this.context.initialAppConfiguration?.regenerateExistingFocusKeywords == null ?
                <div className={'neos-control-group'}>
                    <label className="neos-checkbox">
                        <input
                            type="checkbox"
                            onChange={e => this.context.updateAppConfiguration({regenerateExistingFocusKeywords: e.target.checked})}
                            checked={this.context.appConfiguration.regenerateExistingFocusKeywords}
                            value="1" />
                        <span></span>
                        {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.regenerateExistingFocusKeywords.label', 'Regenerate existing focus keywords automatically')}
                    </label>
                </div> : null
        )
    }

    render() {
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <p style={{marginBottom: '1rem'}} dangerouslySetInnerHTML={{__html: this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:intro', '')}}/>
                <BackendMessage identifier="focus-keyword"/>

                <h2>Auswahl-Filter:</h2>
                <br/>
                {this.renderWorkspaceField()}
                {this.renderModeField()}
                {this.renderNodeTypeFilterField()}
                <LimitField configuration={this.context.appConfiguration} updateConfiguration={this.context.updateAppConfiguration}/>
                <br/>
                <br/>
                <h2>Aktionen:</h2>
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

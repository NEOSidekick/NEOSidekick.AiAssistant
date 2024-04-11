import PureComponent from "./PureComponent";
import {connect} from "react-redux";
import StateInterface from "../Store/StateInterface";
import {setModuleConfiguration} from "../Store/AppSlice";
import LimitField from "./ConfigurationForm/LimitField";
import React from "react";
import {enumKeys} from "../Util";
import {FocusKeywordModuleConfiguration, FocusKeywordModuleMode} from "../Model/FocusKeywordModuleConfiguration";
import PropTypes from "prop-types";

@connect((state: StateInterface) => ({
    configuration: state.app.moduleConfiguration,
    initialConfiguration: state.app.initialModuleConfiguration,
    availableNodeTypeFilters: state.app.availableNodeTypeFilters
}), (dispatch) => ({
    updateConfiguration(moduleConfiguration: FocusKeywordModuleConfiguration) {
        dispatch(setModuleConfiguration({moduleConfiguration}))
    }
}))
export default class FocusKeywordModuleConfigurationForm extends PureComponent {
    static propTypes = {
        configuration: PropTypes.object,
        initialConfiguration: PropTypes.object,
        availableNodeTypeFilters: PropTypes.object
    }

    private renderNodeTypeFilterField() {
        const {configuration, initialConfiguration, updateConfiguration, availableNodeTypeFilters} = this.props;
        return (initialConfiguration?.nodeTypeFilter == null ?
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>
                    {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.nodeTypeFilter.label', 'Restrict to page type')}
                </label>
                <div className={'neos-controls'}>
                    <select
                        value={configuration.nodeTypeFilter}
                        onChange={e => updateConfiguration({nodeTypeFilter: e.target.value})}>
                        <option value={null}>{this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.nodeTypeFilter.all', 'All page types')}</option>
                        {Object.keys(availableNodeTypeFilters).map(nodeType =>
                            <option value={nodeType}>
                                {availableNodeTypeFilters[nodeType]}
                            </option>
                        )}
                    </select>
                </div>
            </div> : null
        )
    }

    private renderWorkspaceField() {
        const {configuration, initialConfiguration, updateConfiguration} = this.props;
        const availableWorkspaces = window['_NEOSIDEKICK_AIASSISTANT_workspaces']
        return (initialConfiguration?.workspace == null ?
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>
                    {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.workspace.label', 'Workspace')}
                </label>
                <div className={'neos-controls'}>
                    <select
                        value={configuration.workspace}
                        onChange={e => updateConfiguration({workspace: e.target.value})}>
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
        const {configuration, initialConfiguration, updateConfiguration} = this.props;
        return (initialConfiguration?.mode == null ?
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>
                    {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.mode.label', 'Selection of pages')}
                </label>
                <div className={'neos-controls'}>
                    <select
                        value={configuration.mode}
                        onChange={e => updateConfiguration({mode: e.target.value})}>
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
        const {configuration, initialConfiguration, updateConfiguration} = this.props;
        return (initialConfiguration?.generateEmptyFocusKeywords == null ?
            <div className={'neos-control-group'}>
                <label className="neos-checkbox">
                    <input
                        type="checkbox"
                        onChange={e => updateConfiguration({generateEmptyFocusKeywords: e.target.checked})}
                        checked={configuration.generateEmptyFocusKeywords}
                        value="1" />
                    <span></span>
                    {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.generateEmptyFocusKeywords.label', 'Generate empty focus keywords automatically')}
                </label>
            </div> : null
        )
    }

    private renderRegenerateExistingFocusKeywordsField()
    {
        const {configuration, initialConfiguration, updateConfiguration} = this.props;
        return (initialConfiguration?.regenerateExistingFocusKeywords == null ?
                <div className={'neos-control-group'}>
                    <label className="neos-checkbox">
                        <input
                            type="checkbox"
                            onChange={e => updateConfiguration({regenerateExistingFocusKeywords: e.target.checked})}
                            checked={configuration.regenerateExistingFocusKeywords}
                            value="1" />
                        <span></span>
                        {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:configuration.regenerateExistingFocusKeywords.label', 'Regenerate existing focus keywords automatically')}
                    </label>
                </div> : null
        )
    }

    render() {
        const {configuration, updateConfiguration} = this.props;
        return (
            <div>
                <h2>Auswahl-Filter:</h2>
                <br />
                {this.renderWorkspaceField()}
                {this.renderModeField()}
                {this.renderNodeTypeFilterField()}
                <LimitField configuration={configuration} updateConfiguration={updateConfiguration} />
                <br />
                <br />
                <h2>Aktionen:</h2>
                <br />
                {this.renderGenerateEmptyFocusKeywordsField()}
                {this.renderRegenerateExistingFocusKeywordsField()}
            </div>
        )
    }
}

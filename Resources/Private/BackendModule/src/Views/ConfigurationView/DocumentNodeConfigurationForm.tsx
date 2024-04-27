import PureComponent from "../../Components/PureComponent";
import ItemsPerPageField from "./ItemsPerPageField";
import React from "react";
import {enumKeys} from "../../Util";
import {DocumentNodeModuleConfiguration, FocusKeywordModuleMode} from "../../Model/ModuleConfiguration";
import BackendMessage from "../../Components/BackendMessage";
import StartModuleButton from "./StartModuleButton";
import AppContext, {AppContextType} from "../../AppContext";
import SelectField from "../../Components/Field/SelectField";
import CheckboxField from "../../Components/Field/CheckboxField";
import NodeTypeFilter from "./DocumentNodeConfigurationForm.NodeTypeFilter";

interface DocumentNodeConfigurationFormProps {
    // we take this as a prop just for typing the moduleConfiguration
    moduleConfiguration: DocumentNodeModuleConfiguration
}

function capitalizeFirstLetter(string: string) {
    return string.charAt(0).toUpperCase() + string.slice(1);
}

export default class DocumentNodeConfigurationForm extends PureComponent<DocumentNodeConfigurationFormProps> {
    static contextType = AppContext;
    context: AppContextType;

    private renderWorkspaceField() {
        const workspaces = this.context.workspaces;
        const moduleConfiguration = this.props.moduleConfiguration;
        return (moduleConfiguration.enforceConfigs.includes('workspace') ? null :
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNodeModule:configuration.workspace.label', 'Workspace')}
                value={moduleConfiguration.workspace}
                onChange={e => this.context.updateModuleConfiguration({workspace: e.target.value})}
                options={Object.keys(workspaces).reduce((acc, workspaceIdentifier) => {
                    const workspace = workspaces[workspaceIdentifier];
                    acc[workspace.name] = capitalizeFirstLetter(workspace.title || workspace.name.replace('user-', 'User: '));
                    return acc
                }, {})}
            />
        )
    }

    private renderModeField() {
        const moduleConfiguration = this.props.moduleConfiguration;
        return (moduleConfiguration.enforceConfigs.includes('workspace') ? null :
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNodeModule:configuration.mode.label', 'Selection of pages')}
                value={moduleConfiguration.mode}
                onChange={e => this.context.updateModuleConfiguration({mode: e.target.value as unknown as FocusKeywordModuleMode})}
                options={enumKeys(FocusKeywordModuleMode).reduce((acc, mode) => {
                    acc[mode] = this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNodeModule:configuration.mode.' + mode, mode);
                    return acc;
                }, {})}
            />
        )
    }

    private renderGenerateEmptyFocusKeywordsField()
    {
        const moduleConfiguration = this.props.moduleConfiguration;
        return (moduleConfiguration.enforceConfigs.includes('generateEmptyFocusKeywords') ? null :
            <CheckboxField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNodeModule:configuration.generateEmptyFocusKeywords.label', 'Generate empty focus keywords automatically')}
                checked={moduleConfiguration.generateEmptyFocusKeywords}
                onChange={e => this.context.updateModuleConfiguration({generateEmptyFocusKeywords: e.target.checked})}
            />
        )
    }

    private renderRegenerateExistingFocusKeywordsField()
    {
        const moduleConfiguration = this.props.moduleConfiguration;
        return (moduleConfiguration.enforceConfigs.includes('regenerateExistingFocusKeywords') ? null :
            <CheckboxField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNodeModule:configuration.regenerateExistingFocusKeywords.label', 'Regenerate existing focus keywords automatically')}
                checked={moduleConfiguration.regenerateExistingFocusKeywords}
                onChange={e => this.context.updateModuleConfiguration({regenerateExistingFocusKeywords: e.target.checked})}
            />
        )
    }

    render() {
        const moduleConfiguration = this.props.moduleConfiguration;
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <p style={{marginBottom: '1rem', maxWidth: '80ch'}}
                   dangerouslySetInnerHTML={{__html: this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.FocusKeywordModule:description', '')}}/>
                <BackendMessage identifier="focus-keyword"/>

                <h2>{this.translationService.translate('NEOSidekick.AiAssistant:Module:selectionFilter', '')}:</h2>
                <br/>
                {this.renderWorkspaceField()}
                {this.renderModeField()}
                <NodeTypeFilter moduleConfiguration={moduleConfiguration}/>
                <ItemsPerPageField moduleConfiguration={moduleConfiguration} updateModuleConfiguration={this.context.updateModuleConfiguration}/>
                <br/>
                <br/>
                <h2>{this.translationService.translate('NEOSidekick.AiAssistant:Module:actions', '')}:</h2>
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

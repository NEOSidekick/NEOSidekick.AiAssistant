import PureComponent from "../../Components/PureComponent";
import ItemsPerPageField from "./ItemsPerPageField";
import React from "react";
import {DocumentNodeModuleConfiguration} from "../../Model/ModuleConfiguration";
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
        const {moduleConfiguration} = this.props;
        return (moduleConfiguration.enforceConfigs.includes('workspace') ? null :
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.workspace.label', 'Workspace')}
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

    private renderLanguageDimensionField() {
        const {moduleConfiguration} = this.props;
        const languageDimensionConfiguration = this.context.languageDimensionConfiguration;
        return (languageDimensionConfiguration ? <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.languageDimensionFilter.label', 'Restrict by language')}
                value={moduleConfiguration.languageDimensionFilter}
                onChange={e => this.context.updateModuleConfiguration({languageDimensionFilter: e.target.value})}
                options={Object.keys(languageDimensionConfiguration.presets).reduce((acc, languageDimensionPreset) => {
                    acc[languageDimensionPreset] = languageDimensionConfiguration['presets'][languageDimensionPreset].label;
                    return acc
                }, {'': this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.languageDimensionFilter.all', 'All languages')})}
            /> : null)
    }

    private renderSeoPropertiesFilterField() {
        const {moduleConfiguration} = this.props;
        return ((moduleConfiguration.enforceConfigs.includes('seoPropertiesFilter') || !moduleConfiguration.seoPropertiesFilterOptions?.length) ? null :
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.seoPropertiesFilter.label', 'Restrict by SEO properties')}
                value={moduleConfiguration.seoPropertiesFilter}
                onChange={e => this.context.updateModuleConfiguration({seoPropertiesFilter: e.target.value})}
                options={moduleConfiguration.seoPropertiesFilterOptions.reduce((acc, seoPropertiesFilter) => {
                    acc[seoPropertiesFilter] = this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.seoPropertiesFilter.' + seoPropertiesFilter, seoPropertiesFilter);
                    return acc;
                }, {})}
            />
        )
    }

    private renderFocusKeywordFilterField() {
        const {moduleConfiguration} = this.props;
        return ((moduleConfiguration.enforceConfigs.includes('focusKeywordPropertyFilter') || !moduleConfiguration.focusKeywordPropertyFilterOptions?.length) ? null :
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.focusKeywordPropertyFilter.label', 'Restrict by focus keyword')}
                value={moduleConfiguration.focusKeywordPropertyFilter}
                onChange={e => this.context.updateModuleConfiguration({focusKeywordPropertyFilter: e.target.value})}
                options={moduleConfiguration.focusKeywordPropertyFilterOptions.reduce((acc, focusKeywordPropertyFilter) => {
                    acc[focusKeywordPropertyFilter] = this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.focusKeywordPropertyFilter.' + focusKeywordPropertyFilter, focusKeywordPropertyFilter);
                    return acc;
                }, {})}
            />
        )
    }

    private renderActions() {
        const {actions} = this.props.moduleConfiguration;
        if (!Object.keys(actions).length) {
            return null;
        }
        return (
            <div>
                <br/>
                <br/>
                <h2>{this.translationService.translate('NEOSidekick.AiAssistant:Module:actions', '')}:</h2>
                <br/>
                {Object.keys(actions).map(actionName => (
                    <CheckboxField
                        key={actionName}
                        label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.action.' + actionName, actionName)}
                        checked={actions[actionName].active}
                        onChange={e => {
                            const actions = this.props.moduleConfiguration.actions;
                            actions[actionName].active = e.target.checked;
                            this.context.updateModuleConfiguration({actions});
                        }}
                    />
                ))}
            </div>
        )
    }

    render() {
        const {moduleConfiguration} = this.props;

        /* to kebab case */
        let moduleNameKebabCase = moduleConfiguration.moduleName.replace(/([a-z0-9]|(?=[A-Z]))([A-Z])/g, '$1-$2').toLowerCase();
        moduleNameKebabCase = moduleNameKebabCase.startsWith('-') ? moduleNameKebabCase.substring(1) : moduleNameKebabCase;

        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <p style={{marginBottom: '1rem', maxWidth: '80ch'}}
                   dangerouslySetInnerHTML={{__html: this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.' + moduleConfiguration.moduleName + ':description', '')}}/>
                <BackendMessage identifier={moduleNameKebabCase + '-generator'}/>

                <h2>{this.translationService.translate('NEOSidekick.AiAssistant:Module:selectionFilter', '')}:</h2>
                <br/>
                {this.renderWorkspaceField()}
                {this.renderLanguageDimensionField()}
                {this.renderSeoPropertiesFilterField()}
                {this.renderFocusKeywordFilterField()}
                <NodeTypeFilter moduleConfiguration={moduleConfiguration}/>
                <ItemsPerPageField moduleConfiguration={moduleConfiguration} updateModuleConfiguration={this.context.updateModuleConfiguration}/>
                {this.renderActions()}
                <div className={'neos-footer'}>
                    <StartModuleButton/>
                </div>
            </div>
        )
    }
}

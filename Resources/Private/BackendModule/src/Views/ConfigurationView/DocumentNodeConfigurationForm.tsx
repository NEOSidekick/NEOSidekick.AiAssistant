import PureComponent from "../../Components/PureComponent";
import ItemsPerPageField from "./ItemsPerPageField";
import React from "react";
import {enumKeys} from "../../Util";
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

    private renderPropertyFilterField() {
        const {moduleConfiguration} = this.props;
        return ((moduleConfiguration.enforceConfigs.includes('propertyFilter') || !moduleConfiguration.propertyFilterOptions?.length) ? null :
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.propertyFilter.label', 'Restrict by properties')}
                value={moduleConfiguration.propertyFilter}
                onChange={e => this.context.updateModuleConfiguration({propertyFilter: e.target.value})}
                options={moduleConfiguration.propertyFilterOptions.reduce((acc, propertyFilter) => {
                    acc[propertyFilter] = this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.propertyFilter.' + propertyFilter, propertyFilter);
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
                {this.renderPropertyFilterField()}
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

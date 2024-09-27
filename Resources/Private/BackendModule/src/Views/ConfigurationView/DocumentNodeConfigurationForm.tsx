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
import ProgressSteps from "../../Components/ProgressSteps";

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
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.workspace.label', 'Workspace (read and write)')}
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
        const {languageDimensionFilter} = this.props.moduleConfiguration;
        const languageDimensionConfiguration = this.context.languageDimensionConfiguration;
        if (!languageDimensionConfiguration) {
            return null;
        }
        return (
            <div>
                <p style={{fontWeight: 'bold', marginBottom: '0.5rem'}}>{this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.languageDimensionFilter.label', 'Select languages')}</p>
                {Object.keys(languageDimensionConfiguration.presets).map(languageDimensionPreset => (
                    <CheckboxField
                        key={languageDimensionPreset}
                        label={languageDimensionConfiguration['presets'][languageDimensionPreset].label}
                        checked={languageDimensionFilter.includes(languageDimensionPreset)}
                        onChange={e => {
                            let languageDimensionFilter = this.props.moduleConfiguration.languageDimensionFilter;
                            if (e.target.checked) {
                                languageDimensionFilter.push(languageDimensionPreset);
                            } else {
                                languageDimensionFilter = languageDimensionFilter.filter(preset => preset !== languageDimensionPreset);
                            }
                            this.context.updateModuleConfiguration({languageDimensionFilter});
                        }}
                    />
                ))}
            </div>
        );
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
        const isSeoImageAlternativeText = moduleConfiguration.moduleName === 'SeoImageAlternativeText';

        /* to kebab case */
        let moduleNameKebabCase = moduleConfiguration.moduleName.replace(/([a-z0-9]|(?=[A-Z]))([A-Z])/g, '$1-$2').toLowerCase();
        moduleNameKebabCase = moduleNameKebabCase.startsWith('-') ? moduleNameKebabCase.substring(1) : moduleNameKebabCase;

        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <div style={{maxWidth: '135ch'}}>
                    <h1 style={{fontSize: '2rem', marginTop: '3rem', marginBottom: '1.5rem', lineHeight: '1.4'}}>
                        {this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:title', 'The Essential Toolkit for Effective On-Page SEO')}
                    </h1>
                    <p style={{marginBottom: '1rem'}}>
                        {this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:intro', 'On-page SEO is a cornerstone of successful digital marketing. It ensures your content is not only visible but also compelling to search engines. Here’s a streamlined toolkit to enhance your content.')}
                    </p>
                    <ProgressSteps steps={[
                        {
                            id: '01',
                            name: this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.FocusKeyword:stepName', 'Define Focus Keywords'),
                            href: '/neos/ai-assistant/focus-keyword-generator',
                            status: moduleConfiguration.moduleName === 'FocusKeyword' ? 'current' : 'upcoming'
                        },
                        {
                            id: '02',
                            name: this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.SeoTitleAndMetaDescription:stepName', 'Write SEO Titles and Meta Descriptions'),
                            href: '/neos/ai-assistant/seo-title-and-meta-description-generator',
                            status: moduleConfiguration.moduleName === 'SeoTitleAndMetaDescription' ? 'current' : 'upcoming'
                        },
                        {
                            id: '03',
                            name: this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.SeoImageAlternativeText:stepName', 'Optimize Image Alt Texts'),
                            href: '/neos/ai-assistant/seo-image-alt-text-generator',
                            status: moduleConfiguration.moduleName === 'SeoImageAlternativeText' ? 'current' : 'upcoming'
                        },
                    ]}/>
                </div>

                {isSeoImageAlternativeText ? (
                    <div style={{maxWidth: '80ch'}}>
                        <p style={{marginBottom: '1rem'}}
                           dangerouslySetInnerHTML={{__html: this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.' + moduleConfiguration.moduleName + ':description', '')}}/>
                    </div>
                ) : (
                    <div style={{maxWidth: '80ch'}}>
                        <BackendMessage identifier={moduleNameKebabCase + '-generator'}/>

                        <p style={{marginBottom: '1rem'}}
                           dangerouslySetInnerHTML={{__html: this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.' + moduleConfiguration.moduleName + ':description', '')}}/>

                        <p style={{marginBottom: '1rem', maxWidth: '80ch'}}>
                            {this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:filterTypeExplanation', 'NEOSidekick can identify the most relevant pages for you and provide suggestions for each of these pages.')}
                        </p>

                        {moduleConfiguration.filter === 'important-pages' && (<div style={{marginBottom: '2rem'}}>
                            {this.renderLanguageDimensionField()}
                            <StartModuleButton label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:startModule', 'Start generation for most important pages')} style={{marginTop: '1rem'}}/>
                        </div>)}

                        <CheckboxField
                            label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.DocumentNode:configuration.filter.custom', 'I know what I\'m doing and would prefer to set individual filters')}
                            checked={moduleConfiguration.filter === 'custom'}
                            onChange={e => this.context.updateModuleConfiguration({filter: e.target.checked ? 'custom' : 'important-pages'})}
                        />

                        {moduleConfiguration.filter === 'custom' && (
                            <div style={{marginTop: '1rem', border: '1px solid #3f3f3f', borderRadius: '0.375rem', padding: '1rem', maxWidth: 'calc(80ch - 2rem)'}}>
                                <h2>{this.translationService.translate('NEOSidekick.AiAssistant:Module:selectionFilter', 'Selection filter')}:</h2>
                                <br/>
                                {this.renderWorkspaceField()}
                                {this.renderLanguageDimensionField()}
                                {this.renderSeoPropertiesFilterField()}
                                {this.renderFocusKeywordFilterField()}
                                <NodeTypeFilter moduleConfiguration={moduleConfiguration}/>
                                <ItemsPerPageField moduleConfiguration={moduleConfiguration}
                                                   updateModuleConfiguration={this.context.updateModuleConfiguration}/>
                                {this.renderActions()}
                            </div>)}
                    </div>
                )}

                {moduleConfiguration.filter === 'custom' && (<div className={'neos-footer'}>
                    <StartModuleButton/>
                </div>)}
            </div>
        )
    }
}

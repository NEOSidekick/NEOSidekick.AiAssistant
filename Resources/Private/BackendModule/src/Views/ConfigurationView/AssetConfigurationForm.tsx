import React, {ReactElement} from "react";
import {AssetModuleConfiguration, Language} from "../../Model/ModuleConfiguration";
import PureComponent from "../../Components/PureComponent";
import {enumKeys} from "../../Util";
import ItemsPerPageField from "./ItemsPerPageField";
import BackendMessage from "../../Components/BackendMessage";
import StartModuleButton from "./StartModuleButton";
import AppContext, {AppContextType} from "../../AppContext";
import SelectField from "../../Components/Field/SelectField";

interface AssetConfigurationFormProps {
    // we take this as a prop just for typing the moduleConfiguration
    moduleConfiguration: AssetModuleConfiguration
}

export default class AssetConfigurationForm extends PureComponent<AssetConfigurationFormProps> {
    static contextType = AppContext;
    context: AppContextType;

    private renderOnlyInUseField(): ReactElement
    {
        const moduleConfiguration = this.props.moduleConfiguration;
        return (moduleConfiguration.enforceConfigs.includes('onlyAssetsInUse') ? null :
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.ImageAlternativeText:configuration.onlyAssetsInUse.label', 'Selection of images')}
                value={moduleConfiguration.onlyAssetsInUse ? 1 : 0}
                onChange={e => this.context.updateModuleConfiguration({onlyAssetsInUse: !!e.target.value})}
                options={{
                    0: this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.ImageAlternativeText:configuration.onlyAssetsInUse.0', 'All images'),
                    1: this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.ImageAlternativeText:configuration.onlyAssetsInUse.1', 'Only images in use'),
                }}
            />
        )
    }

    private renderPropertyNameField(): ReactElement
    {
        const moduleConfiguration = this.props.moduleConfiguration;
        return (moduleConfiguration.enforceConfigs.includes('propertyName') ? null :
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.ImageAlternativeText:configuration.propertyName.label', 'Welches Feld?')}
                value={moduleConfiguration.propertyName}
                onChange={e => {
                    const propertyName = e.target.value as 'title' | 'caption';
                    this.context.updateModuleConfiguration({propertyName: propertyName, editableProperties: [propertyName]});
                }}
                options={{
                    'title': this.translationService.translate('Neos.Media.Browser:Main:field_title', 'title'),
                    'caption': this.translationService.translate('Neos.Media.Browser:Main:field_caption', 'caption'),
                }}
            />
        )
    }

    private renderLanguageField(): ReactElement
    {
        const moduleConfiguration = this.props.moduleConfiguration;
        return (moduleConfiguration.enforceConfigs.includes('language') ? null :
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.ImageAlternativeText:configuration.language.label', 'Which language?')}
                value={moduleConfiguration.language}
                onChange={e => this.context.updateModuleConfiguration({language: e.target.value as unknown as Language})}
                options={enumKeys(Language).reduce((acc, language) => {
                    acc[language] = this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.ImageAlternativeText:configuration.language.' + language, language);
                    return acc;
                }, {})}
            />
        )
    }

    render() {
        const moduleConfiguration = this.props.moduleConfiguration;
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <p style={{marginBottom: '1rem', maxWidth: '80ch'}}
                   dangerouslySetInnerHTML={{__html: this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.ImageAlternativeText:intro', 'With this tool, you can create image descriptions and save them in the title or description field of the media asses. These descriptions are optimized as image alternative texts for SEO and accessibility. <a href="https://neosidekick.com/produkt/features/bildbeschreibungs-generator" target="_blank" style="text-decoration: underline;">Read the tutorial on how a developer can integrate them.</a>')}}/>
                <BackendMessage identifier="alternate-image-text-generator"/>

                <h2>{this.translationService.translate('NEOSidekick.AiAssistant:Module:selectionFilter', '')}:</h2>
                <br/>
                {this.renderOnlyInUseField()}
                <ItemsPerPageField moduleConfiguration={moduleConfiguration} updateModuleConfiguration={this.context.updateModuleConfiguration}/>
                <br/>
                <br/>
                <h2>{this.translationService.translate('NEOSidekick.AiAssistant:Module:actions', '')}:</h2>
                <br/>
                {this.renderPropertyNameField()}
                {this.renderLanguageField()}

                <div className={'neos-footer'}>
                    <StartModuleButton/>
                </div>
            </div>
        )
    }
}

import React, {ReactElement} from "react";
import {AssetPropertyName, Language, OnlyAssetsInUse} from "../../Model/AssetModuleConfiguration";
import PureComponent from "../PureComponent";
import {enumKeys} from "../../Util";
import LimitField from "./LimitField";
import BackendMessage from "../BackendMessage";
import StartModuleButton from "../StartModuleButton";
import AppContext from "../../AppContext";
import SelectField from "./SelectField";

export default class AssetConfigurationForm extends PureComponent {
    static contextType = AppContext

    private renderOnlyInUseField(): ReactElement
    {
        // This is intentionally a "==" comparison to both match null and undefined
        return (this.context.initialAppConfiguration?.onlyAssetsInUse == null ?
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.onlyAssetsInUse.label', 'Selection of images')}
                value={this.context.appConfiguration.onlyAssetsInUse}
                defaultValue={OnlyAssetsInUse.all}
                onChange={e => this.context.updateAppConfiguration({onlyAssetsInUse: e.target.value})}
                options={{
                    [OnlyAssetsInUse.all]: this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.onlyAssetsInUse.0', 'All images'),
                    [OnlyAssetsInUse.onlyInUse]: this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.onlyAssetsInUse.1', 'Only images in use'),
                }}
            /> : null
        )
    }

    private renderPropertyNameField(): ReactElement
    {
        // This is intentionally a "==" comparison to both match null and undefined
        return (this.context.initialConfiguration?.propertyName == null ?
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.propertyName.label', 'Welches Feld?')}
                value={this.context.appConfiguration.propertyName}
                defaultValue={AssetPropertyName.title}
                onChange={e => this.context.updateAppConfiguration({propertyName: e.target.value})}
                options={enumKeys(AssetPropertyName).reduce((acc, key) => {
                    acc[key] = this.translationService.translate('Neos.Media.Browser:Main:field_' + key, key);
                    return acc;
                }, {})}
            /> : null
        )
    }

    private renderLanguageField(): ReactElement
    {
        return (this.context.initialConfiguration?.language == null ?
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.language.label', 'Which language?')}
                value={this.context.appConfiguration.language}
                onChange={e => this.context.updateAppConfiguration({language: e.target.value})}
                options={enumKeys(Language).reduce((acc, language) => {
                    acc[language] = this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.language.' + language, language);
                    return acc;
                }, {})}
            /> : null
        )
    }

    render() {
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <p style={{marginBottom: '1rem'}}
                   dangerouslySetInnerHTML={{__html: this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:intro', 'With this tool, you can create image descriptions and save them in the title or description field of the media asses. These descriptions are optimized as image alternative texts for SEO and accessibility. <a href="https://neosidekick.com/produkt/features/bildbeschreibungs-generator" target="_blank" style="text-decoration: underline;">Read the tutorial on how a developer can integrate them.</a>')}}/>
                <BackendMessage identifier="bulk-image-generation"/>

                <h2>{this.translationService.translate('NEOSidekick.AiAssistant:Main:selectionFilter', '')}</h2>
                <br/>
                {this.renderOnlyInUseField()}
                {this.renderPropertyNameField()}
                <LimitField configuration={this.context.appConfiguration} updateConfiguration={this.context.updateAppConfiguration}/>
                {this.renderLanguageField()}


                <div className={'neos-footer'}>
                    <StartModuleButton/>
                </div>
            </div>
        )
    }
}

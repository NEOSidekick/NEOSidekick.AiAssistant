import React, {ReactElement} from "react";
import {AssetPropertyName, Language, OnlyAssetsInUse} from "../../Model/AssetModuleConfiguration";
import PureComponent from "../PureComponent";
import {enumKeys} from "../../Util";
import LimitField from "./LimitField";
import BackendMessage from "../BackendMessage";
import StartModuleButton from "../StartModuleButton";
import AppContext from "../../AppContext";

export default class AssetConfigurationForm extends PureComponent {
    static contextType = AppContext

    private renderOnlyInUseField(): ReactElement
    {
        // This is intentionally a "==" comparison to both match null and undefined
        return (this.context.initialAppConfiguration?.onlyAssetsInUse == null ?
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>
                    {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.onlyAssetsInUse.label', 'Selection of images')}
                </label>
                <div className={'neos-controls'}>
                    <select
                        value={this.context.appConfiguration.onlyAssetsInUse}
                        onChange={e => this.context.updateAppConfiguration({onlyAssetsInUse: e.target.value})}
                        defaultValue={OnlyAssetsInUse.all}>
                        <option value={OnlyAssetsInUse.all}>{this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.onlyAssetsInUse.0', 'All images')}</option>
                        <option value={OnlyAssetsInUse.onlyInUse}>{this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.onlyAssetsInUse.1', 'Only images in use')}</option>
                    </select>
                </div>
            </div> : null
        )
    }

    private renderPropertyNameField(): ReactElement
    {
        const {configuration, initialConfiguration, updateConfiguration} = this.props;
        // This is intentionally a "==" comparison to both match null and undefined
        return (initialConfiguration?.propertyName == null ?
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>
                    {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.propertyName.label', 'Welches Feld?')}
                </label>
                <div className={'neos-controls'}>
                    <select
                        value={configuration.propertyName}
                        onChange={e => updateConfiguration({propertyName: e.target.value})}
                        defaultValue={AssetPropertyName.title}>
                        {enumKeys(AssetPropertyName).map(key =>
                            <option value={key}>
                                {this.translationService.translate('Neos.Media.Browser:Main:field_' + key, key)}
                            </option>
                        )}
                    </select>
                </div>
            </div> : null
        )
    }

    private renderLanguageField(): ReactElement
    {
        const {configuration, initialConfiguration, updateConfiguration} = this.props;
        return (initialConfiguration?.language == null ?
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>
                    {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.language.label', 'Which language?')}
                </label>
                <div className={'neos-controls'}>
                    <select
                        value={configuration.language}
                        onChange={e => updateConfiguration({language: e.target.value})}>
                        {enumKeys(Language).map(language =>
                            <option value={language}>
                                {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.language.' + language, language)}
                            </option>
                        )}
                    </select>
                </div>
            </div> : null
        )
    }

    render() {
        const {configuration, updateConfiguration} = this.props;
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <p style={{marginBottom: '1rem'}}
                   dangerouslySetInnerHTML={{__html: this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:intro', 'With this tool, you can create image descriptions and save them in the title or description field of the media asses. These descriptions are optimized as image alternative texts for SEO and accessibility. <a href="https://neosidekick.com/produkt/features/bildbeschreibungs-generator" target="_blank" style="text-decoration: underline;">Read the tutorial on how a developer can integrate them.</a>')}}/>
                <BackendMessage identifier="bulk-image-generation"/>
                {this.renderOnlyInUseField()}
                {this.renderPropertyNameField()}
                <LimitField configuration={configuration} updateConfiguration={updateConfiguration}/>
                {this.renderLanguageField()}
                <div className={'neos-footer'}>
                    <StartModuleButton/>
                </div>
            </div>
        )
    }
}

import React, {ReactElement} from "react";
import {connect} from "react-redux";
import PropTypes from "prop-types";
import {AssetModuleConfiguration,
    AssetPropertyName, Language,
    OnlyAssetsInUse
} from "../Model/AssetModuleConfiguration";
import StateInterface from "../Store/StateInterface";
import {setModuleConfiguration} from "../Store/AppSlice";
import PureComponent from "./PureComponent";
import {enumKeys} from "../Util";
import LimitField from "./ConfigurationForm/LimitField";

@connect((state: StateInterface) => ({
    configuration: state.app.moduleConfiguration,
    initialConfiguration: state.app.initialModuleConfiguration,
}), (dispatch) => ({
    updateConfiguration(moduleConfiguration: AssetModuleConfiguration) {
        dispatch(setModuleConfiguration({moduleConfiguration}))
    }
}))
export default class AssetModuleConfigurationForm extends PureComponent<ConfigurationFormProps> {
    static propTypes = {
        configuration: PropTypes.object,
        initialConfiguration: PropTypes.object,
        updateConfiguration: PropTypes.func
    }

    private renderOnlyInUseField(): ReactElement
    {
        const {configuration, initialConfiguration, updateConfiguration} = this.props;
        // This is intentionally a "==" comparison to both match null and undefined
        return (initialConfiguration?.onlyAssetsInUse == null ?
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>
                    {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.onlyAssetsInUse.label', 'Selection of images')}
                </label>
                <div className={'neos-controls'}>
                    <select
                        value={configuration.onlyAssetsInUse}
                        onChange={e => updateConfiguration({onlyAssetsInUse: e.target.value})}
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
            <div>
                {this.renderOnlyInUseField()}
                {this.renderPropertyNameField()}
                <LimitField configuration={configuration} updateConfiguration={updateConfiguration} />
                {this.renderLanguageField()}
            </div>
        )
    }
}

interface ConfigurationFormProps {
    configuration: AssetModuleConfiguration
}

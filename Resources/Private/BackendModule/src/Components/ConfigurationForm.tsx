import React from "react";
import {connect} from "react-redux";
import PropTypes from "prop-types";
import AssetModuleConfigurationInterface, {
    AssetPropertyName,
    OnlyAssetsInUse
} from "../Model/AssetModuleConfigurationInterface";
import StateInterface from "../Store/StateInterface";
import {setModuleConfiguration, startModule} from "../Store/AppSlice";
import PureComponent from "./PureComponent";

@connect((state: StateInterface) => ({
    started: state.app.started,
    configuration: state.app.moduleConfiguration
}), (dispatch, ownProps) => ({
    updateConfiguration(moduleConfiguration: AssetModuleConfigurationInterface) {
        dispatch(setModuleConfiguration({moduleConfiguration}))
    }
}))
export default class ConfigurationForm extends PureComponent<ConfigurationFormProps> {
    static propTypes = {
        started: PropTypes.bool,
        configuration: PropTypes.object,
        updateConfiguration: PropTypes.func
    }

    render() {
        const {started, configuration, updateConfiguration} = this.props;
        return (!started ?
            <div style={{marginBottom: '1rem'}}>
                <div className={'neos-control-group'}>
                    <label className={'neos-control-label'}>Alle oder nur verwendete?</label>
                    <div className={'neos-controls'}>
                        <select
                            value={configuration.onlyAssetsInUse}
                            onChange={e => updateConfiguration({onlyAssetsInUse: e.target.value})}
                            defaultValue={OnlyAssetsInUse.all}>
                            <option value={OnlyAssetsInUse.all}>all</option>
                            <option value={OnlyAssetsInUse.onlyInUse}>only in use</option>
                        </select>
                    </div>
                </div>
                <div className={'neos-control-group'}>
                    <label className={'neos-control-label'}>Feld</label>
                    <div className={'neos-controls'}>
                        <select
                            value={configuration.propertyName}
                            onChange={e => updateConfiguration({propertyName: e.target.value})}
                            defaultValue={AssetPropertyName.title}>
                            {Object.keys(AssetPropertyName).filter((v) => isNaN(Number(v))).map(key => <option value={key}>{this.translationService.translate('Neos.Media.Browser:Main:field_' + key, key)}</option>)}
                        </select>
                    </div>
                </div>
                <div className={'neos-control-group'}>
                    <label className={'neos-control-label'}>Anzahl der Elemente</label>
                    <div className={'neos-controls'}>
                        <select
                            value={configuration.limit}
                            onChange={e => updateConfiguration({limit: e.target.value})}
                            defaultValue={10}>
                            <option>5</option>
                            <option>10</option>
                            <option>15</option>
                            <option>20</option>
                            <option>25</option>
                        </select>
                    </div>
                </div>
            </div> : null
        )
    }
}

interface ConfigurationFormProps {
    configuration: AssetModuleConfigurationInterface
}

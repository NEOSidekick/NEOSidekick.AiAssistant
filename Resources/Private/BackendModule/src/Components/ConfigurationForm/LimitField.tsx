import PureComponent from "../PureComponent";
import PropTypes from "prop-types";
import React from "react";

export default class LimitField extends PureComponent {
    static propTypes = {
        configuration: PropTypes.object,
        updateConfiguration: PropTypes.func
    }

    render() {
        const {configuration, updateConfiguration} = this.props;
        return (
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>
                    {this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.limit.label', 'How many images per page?')}
                </label>
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
        )
    }
}

import PureComponent from "../PureComponent";
import PropTypes from "prop-types";
import React from "react";
import SelectField from "./SelectField";

export default class LimitField extends PureComponent {
    static propTypes = {
        configuration: PropTypes.object,
        updateConfiguration: PropTypes.func
    }

    render() {
        const {configuration, updateConfiguration} = this.props;
        return (
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:configuration.limit.label', 'How many images per page?')}
                value={configuration.limit}
                defaultValue={10}
                options={{
                    5: '5',
                    10: '10',
                    15: '15',
                    20: '20',
                    25: '25',
                }}
                onChange={e => updateConfiguration({limit: e.target.value})}
            />
        )
    }
}

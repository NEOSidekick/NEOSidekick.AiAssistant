import PureComponent from "../../Components/PureComponent";
import React from "react";
import SelectField from "../../Components/Field/SelectField";
import {ModuleConfiguration} from "../../Model/ModuleConfiguration";

interface ItemsPerPageFieldProps {
    moduleConfiguration: ModuleConfiguration,
    updateModuleConfiguration: Function
}

export default class ItemsPerPageField extends PureComponent<ItemsPerPageFieldProps> {
    render() {
        const {moduleConfiguration, updateModuleConfiguration} = this.props;
        return (
            <SelectField
                label={this.translationService.translate('NEOSidekick.AiAssistant:BackendModule.ImageAlternativeText:configuration.itemsPerPage.label', 'How many images per page?')}
                value={moduleConfiguration.itemsPerPage}
                defaultValue={10}
                options={{
                    5: '5',
                    10: '10',
                    15: '15',
                    20: '20',
                    25: '25',
                }}
                onChange={e => updateModuleConfiguration({itemsPerPage: e.target.value * 1})}
            />
        )
    }
}

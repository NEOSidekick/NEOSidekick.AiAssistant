import PureComponent from "../../Components/PureComponent";
import React from "react";
import AssetConfigurationForm from "./AssetConfigurationForm";
import DocumentNodeConfigurationForm from "./DocumentNodeConfigurationForm";
import AppContext from "../../AppContext";
import {AssetModuleConfiguration} from "../../Model/AssetModuleConfiguration";
import {FocusKeywordModuleConfiguration} from "../../Model/FocusKeywordModuleConfiguration";

export default class ConfigurationView extends PureComponent {
    static contextType = AppContext
    render() {
        const {moduleConfiguration} = this.context;
        switch(moduleConfiguration.itemType) {
            case 'Asset':
                return <AssetConfigurationForm moduleConfiguration={moduleConfiguration as AssetModuleConfiguration}/>;
            case 'DocumentNode':
                return <DocumentNodeConfigurationForm moduleConfiguration={moduleConfiguration as FocusKeywordModuleConfiguration}/>;
        }
    }
}

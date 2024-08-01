import PureComponent from "../../Components/PureComponent";
import React from "react";
import AssetConfigurationForm from "./AssetConfigurationForm";
import DocumentNodeConfigurationForm from "./DocumentNodeConfigurationForm";
import AppContext, {AppContextType} from "../../AppContext";
import {
    AssetModuleConfiguration,
    ContentNodeModuleConfiguration,
    DocumentNodeModuleConfiguration
} from "../../Model/ModuleConfiguration";
import ContentNodeConfigurationForm from "./ContentNodeConfigurationForm";

export default class ConfigurationView extends PureComponent {
    static contextType = AppContext;
    context: AppContextType;

    render() {
        const {moduleConfiguration} = this.context;
        switch(moduleConfiguration.itemType) {
            case 'Asset':
                return <AssetConfigurationForm moduleConfiguration={moduleConfiguration as AssetModuleConfiguration}/>;
            case 'DocumentNode':
                return <DocumentNodeConfigurationForm moduleConfiguration={moduleConfiguration as DocumentNodeModuleConfiguration}/>;
            case 'ContentNode':
                return <ContentNodeConfigurationForm moduleConfiguration={moduleConfiguration as ContentNodeModuleConfiguration}/>;
        }
    }
}

import PureComponent from "../PureComponent";
import React from "react";
import AssetConfigurationForm from "../ConfigurationForm/AssetConfigurationForm";
import DocumentNodeConfigurationForm from "../ConfigurationForm/DocumentNodeConfigurationForm";
import AppContext from "../../AppContext";

export default class ConfigurationView extends PureComponent<ConfigurationViewProps> {
    render() {
        switch(this.props.itemType) {
            case 'Asset':
                return <AssetConfigurationForm/>;
            case 'DocumentNode':
                return <DocumentNodeConfigurationForm/>;
        }
    }
}

interface ConfigurationViewProps {
    itemType: 'Asset' | 'DocumentNode',
}

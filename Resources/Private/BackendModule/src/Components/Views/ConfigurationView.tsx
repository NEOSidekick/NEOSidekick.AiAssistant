import PureComponent from "../PureComponent";
import React from "react";
import AssetConfigurationForm from "../ConfigurationForm/AssetConfigurationForm";
import DocumentNodeConfigurationForm from "../ConfigurationForm/DocumentNodeConfigurationForm";
import AppContext from "../../AppContext";

export default class ConfigurationView extends PureComponent {
    static contextType = AppContext;

    constructor(props) {
        super(props);
        console.log(this.context);
    }

    render() {
        console.log(this.context)
        switch(this.context.scope) {
            case 'altTextGeneratorModule':
                return <AssetConfigurationForm/>;
            case 'focusKeywordGeneratorModule':
                return <DocumentNodeConfigurationForm/>;
        }
    }
}

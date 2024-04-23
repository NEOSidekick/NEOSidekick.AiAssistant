import PureComponent from "../PureComponent";
import {connect} from "react-redux";
import StateInterface from "../../Store/StateInterface";
import React from "react";
import AssetConfigurationForm from "../ConfigurationForm/AssetConfigurationForm";
import PropTypes from "prop-types";
import DocumentNodeConfigurationForm from "../ConfigurationForm/DocumentNodeConfigurationForm";

@connect((state: StateInterface) => ({
    scope: state.app.scope
}))
export default class ConfigurationView extends PureComponent {
    static propTypes = {
        scope: PropTypes.string
    }

    render() {
        const {scope} = this.props;
        switch(scope) {
            case 'altTextGeneratorModule':
                return <AssetConfigurationForm/>;
            case 'focusKeywordGeneratorModule':
                return <DocumentNodeConfigurationForm/>;
        }
    }
}

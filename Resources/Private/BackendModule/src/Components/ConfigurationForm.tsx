import PureComponent from "./PureComponent";
import {connect} from "react-redux";
import StateInterface from "../Store/StateInterface";
import React from "react";
import AssetModuleConfigurationForm from "./AssetModuleConfigurationForm";
import PropTypes from "prop-types";
import FocusKeywordModuleConfigurationForm from "./FocusKeywordModuleConfigurationForm";

@connect((state: StateInterface) => ({
    started: state.app.started,
    scope: state.app.scope,
    hasError: state.app.hasError,
    backendMessage: state.app.backendMessage
}))
export default class ConfigurationForm extends PureComponent {
    static propTypes = {
        started: PropTypes.bool,
        hasError: PropTypes.bool,
        backendMessage: PropTypes.string,
        scope: PropTypes.string
    }
    renderScopedConfigurationForm() {
        const {scope} = this.props;
        switch(scope) {
            case 'altTextGeneratorModule':
                return <AssetModuleConfigurationForm/>;
            case 'focusKeywordGeneratorModule':
                return <FocusKeywordModuleConfigurationForm/>;
        }
    }

    renderScopedIntro() {
        const {scope} = this.props;
        switch(scope) {
            case 'altTextGeneratorModule':
                return <p style={{marginBottom: '1rem'}} dangerouslySetInnerHTML={{__html: this.translationService.translate('NEOSidekick.AiAssistant:AssetModule:intro', 'With this tool, you can create image descriptions and save them in the title or description field of the media asses. These descriptions are optimized as image alternative texts for SEO and accessibility. <a href="https://neosidekick.com/produkt/features/bildbeschreibungs-generator" target="_blank" style="text-decoration: underline;">Read the tutorial on how a developer can integrate them.</a>')}}/>
            case 'focusKeywordGeneratorModule':
                return <p style={{marginBottom: '1rem'}} dangerouslySetInnerHTML={{__html: this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:intro', '')}}/>
        }
    }

    render() {
        const {started, hasError, backendMessage} = this.props;
        return ((!started && !hasError) ?
                <div style={{marginBottom: '1rem', maxWidth: '600px'}}>
                    {this.renderScopedIntro()}
                    <div style={{marginBottom: '1.5rem'}} dangerouslySetInnerHTML={{__html: backendMessage}}/>
                    {this.renderScopedConfigurationForm()}
                </div> : null
        )
    }
}

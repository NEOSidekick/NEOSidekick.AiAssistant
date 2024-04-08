import React, {PureComponent} from 'react';
import {connect} from "react-redux";
import PropTypes from "prop-types";
import {selectors} from "@neos-project/neos-ui-redux-store";
import {neos} from "@neos-project/neos-ui-decorators";
import {I18nRegistry} from "@neos-project/neos-ts-interfaces";
import {SidekickFrontendConfiguration} from "../../interfaces";

interface SidekickIFrameProps {
    configuration: SidekickFrontendConfiguration;
    i18nRegistry: I18nRegistry;
    activeContentDimensions: any;
    interfaceLanguage: any;
    className: string;
}
/**
 * This component is expected to exist only once.
 */
@neos((globalRegistry: any) => ({
    configuration: globalRegistry.get('NEOSidekick.AiAssistant').get('configuration'),
    i18nRegistry: globalRegistry.get('i18n'),
}))
@connect(state => ({
    activeContentDimensions: selectors.CR.ContentDimensions.active(state),
    interfaceLanguage: state.user?.preferences?.interfaceLanguage,
}), {})
export default class SidekickIFrame extends PureComponent<SidekickIFrameProps> {
    static propTypes = {
        configuration: PropTypes.object.isRequired,
        i18nRegistry: PropTypes.object.isRequired,
        activeContentDimensions: PropTypes.object.isRequired,
        interfaceLanguage: PropTypes.string.isRequired,
        // API:
        className: PropTypes.string,
    };

    getUri() {
        const {configuration, activeContentDimensions, interfaceLanguage} = this.props;
        const iframeSrc = new URL(`${configuration.apiDomain}/chat/`);
        iframeSrc.searchParams.append('contentLanguage', activeContentDimensions.language ? activeContentDimensions.language[0] : configuration['defaultLanguage']);
        iframeSrc.searchParams.append('interfaceLanguage', interfaceLanguage);
        iframeSrc.searchParams.append('userId', configuration.userId);
        iframeSrc.searchParams.append('plattform', 'neos');
        iframeSrc.searchParams.append('domain', configuration.domain);
        iframeSrc.searchParams.append('siteName', configuration.siteName)
        if (configuration?.referrer) {
            iframeSrc.searchParams.append('referral', configuration?.referrer);
        }
        if (configuration?.apiKey) {
            iframeSrc.searchParams.append('apikey', configuration?.apiKey);
        }
        return iframeSrc;
    }

    render() {
        const {className} = this.props;

        return (
            <iframe
                id="neosidekickAssistant"
                className={className}
                src={this.getUri().toString()}
                allow="clipboard-write"
                onLoad={(e) => (e.target as HTMLElement).dataset.loaded = "true"}
            />
        );
    }
}

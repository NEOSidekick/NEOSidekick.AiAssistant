// @ts-ignore
import React, {PureComponent} from 'react';
import {connect} from "react-redux";
import PropTypes from "prop-types";

import {selectors} from "@neos-project/neos-ui-redux-store";
import {neos} from "@neos-project/neos-ui-decorators";

@neos((globalRegistry: any) => ({
    configuration: globalRegistry.get('NEOSidekick.AiAssistant').get('configuration'),
}))
@connect(state => ({
    activeContentDimensions: selectors.CR.ContentDimensions.active(state),
    interfaceLanguage: (state) => state?.user?.preferences?.interfaceLanguage,
}), {})
export default class SidekickIFrame extends PureComponent {
    static propTypes = {
        configuration: PropTypes.object.isRequired,
        activeContentDimensions: PropTypes.object.isRequired,
        interfaceLanguage: PropTypes.object.isRequired,
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
        if (configuration?.referral) {
            iframeSrc.searchParams.append('referral', configuration?.referral);
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
                onLoad={(e) => e.target.dataset.loaded = true} />
        );
    }
}

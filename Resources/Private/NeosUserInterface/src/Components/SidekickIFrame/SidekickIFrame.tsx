import React, {PureComponent} from 'react';
import {connect} from "react-redux";
import PropTypes from "prop-types";
import {selectors} from "@neos-project/neos-ui-redux-store";
import {neos} from "@neos-project/neos-ui-decorators";
import {I18nRegistry} from "@neos-project/neos-ts-interfaces";
import {SidekickFrontendConfiguration} from "../../interfaces";
import {fetchEmbedToken} from "../../Service/embedToken";

interface SidekickIFrameProps {
    configuration: SidekickFrontendConfiguration;
    i18nRegistry: I18nRegistry;
    activeContentDimensions: any;
    interfaceLanguage: any;
    className: string;
}

interface SidekickIFrameState {
    embedToken: string | null;
    embedTokenSettled: boolean;
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
export default class SidekickIFrame extends PureComponent<SidekickIFrameProps, SidekickIFrameState> {
    static propTypes = {
        configuration: PropTypes.object.isRequired,
        i18nRegistry: PropTypes.object.isRequired,
        activeContentDimensions: PropTypes.object.isRequired,
        interfaceLanguage: PropTypes.string.isRequired,
        // API:
        className: PropTypes.string,
    };

    state: SidekickIFrameState = {
        embedToken: null,
        embedTokenSettled: false,
    };

    private unmounted = false;

    componentDidMount() {
        // Fetch a fresh embed token before the first iframe load, so the initial bootstrap
        // can release the authBindingToken in one round trip. fetchEmbedToken never rejects
        // and is timeout-bounded (6 s), so the frame is delayed at most that long and NEVER
        // blocked on a failure: settling with null just builds the src without the param,
        // and the assistant then obtains a token on demand through the postMessage channel.
        // This bodyless first-load fetch never pushes the signing key; only the handshake
        // replies do. The token frozen into the src is single-use by design - the SPA
        // re-reads this src on language switches, so it must never be treated as fresh
        // after the first load.
        fetchEmbedToken().then((embedToken) => {
            if (!this.unmounted) {
                this.setState({embedToken, embedTokenSettled: true});
            }
        });
    }

    componentWillUnmount() {
        this.unmounted = true;
    }

    getUri() {
        const {configuration, activeContentDimensions, interfaceLanguage} = this.props;
        const iframeSrc = new URL(`${configuration.apiDomain}/agentic-chat/`);
        iframeSrc.searchParams.append('contentLanguage', activeContentDimensions.language ? activeContentDimensions.language[0] : configuration['defaultLanguage']);
        iframeSrc.searchParams.append('interfaceLanguage', interfaceLanguage);
        iframeSrc.searchParams.append('userId', configuration.userId);
        iframeSrc.searchParams.append('sessionsIsSameSite', configuration.sessionsIsSameSite ? 'true' : 'false');
        iframeSrc.searchParams.append('plattform', 'neos');
        iframeSrc.searchParams.append('domain', configuration.domain);
        iframeSrc.searchParams.append('siteName', configuration.siteName)
        // The Neos backend page's real browser origin. `domain` above is the *site's* public base
        // URI, which may differ from where the backend is served; the assistant needs the actual
        // parent origin to target postMessage back at this window.
        iframeSrc.searchParams.append('parentOrigin', window.location.origin);
        // Purely for server-side rollout segmentation. Omitted when the version is unresolvable
        // (dev checkouts), which the receiving side tolerates.
        if (configuration?.pluginVersion) {
            iframeSrc.searchParams.append('pluginVersion', configuration.pluginVersion);
        }
        if (configuration?.referrer) {
            iframeSrc.searchParams.append('referral', configuration?.referrer);
        }
        if (configuration?.apiKey) {
            iframeSrc.searchParams.append('apikey', configuration?.apiKey);
        }
        if (this.state.embedToken) {
            iframeSrc.searchParams.append('embedToken', this.state.embedToken);
        }
        return iframeSrc;
    }

    render() {
        const {className} = this.props;

        // Until the embed token has settled the src cannot be built yet (see componentDidMount),
        // which would leave the panel blank for up to the fetch timeout. Render the same container
        // with a pulsing placeholder instead - it carries the incoming className, so the existing
        // open/hidden visibility rules apply to it exactly as they do to the iframe.
        if (!this.state.embedTokenSettled) {
            return (
                <div className={`${className} neosidekick__frame--loading`} aria-busy="true">
                    <div className="neosidekick__frame-skeleton"/>
                </div>
            );
        }

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

// @ts-ignore
import manifest from "@neos-project/neos-ui-extensibility";
// @ts-ignore
import { IconButton, Headline } from "@neos-project/react-ui-components";
// @ts-ignore
import {selectors} from '@neos-project/neos-ui-redux-store';
// @ts-ignore
import * as React from 'react';
// @ts-ignore
import { useSelector } from 'react-redux';
import "./style.css";


manifest("NEOSidekick.AiAssistant", {}, (globalRegistry, { frontendConfiguration }) => {
    let configuration = null;
    // Call the configuration endpoint to get the plugin configuration
    // and to check whether the user has permission to use AiAsisstant
    // If not, the endpoint will return a 403 status
    const pingRequest = new XMLHttpRequest();
    pingRequest.open('GET', '/neosidekick/aiassistant/configuration', false);
    pingRequest.withCredentials = true;
    pingRequest.addEventListener('load', (event) => {
        const response = event.target;
        // @ts-ignore
        if (response?.status >= 200 && response?.status <= 299) {
            // @ts-ignore
            configuration = JSON.parse(response.responseText)
        }
    })
    pingRequest.send();

    if (configuration === null) {
        return
    }

    const apikey = configuration?.apikey || ''
    if (!apikey) {
        return
    }

    const containerRegistry = globalRegistry.get('containers');
    const App = containerRegistry.get('App');
	const WrappedApp = (props: Record<string, unknown>) => {
        const state = localStorage.getItem('NEOSidekick') ? JSON.parse(localStorage.getItem('NEOSidekick')) : { open: true, fullscreen: false };
		const [isOpen, setOpen] = React.useState(state?.open);
        const setOpenAndPersistState = (open) => {
            setOpen(open);
            state.open = open;
            localStorage.setItem('NEOSidekick', JSON.stringify(state));
        }

        const [isFullscreen, setFullscreen] = React.useState(state?.fullscreen);
        const setFullscreenAndPersistState = (fullscreen) => {
            setFullscreen(fullscreen);
            state.fullscreen = fullscreen;
            localStorage.setItem('NEOSidekick', JSON.stringify(state));
        }

        const fullscreenButton = (isOpen, isFullscreen, onClick) => {
            if (isOpen) {
                return <IconButton icon={isFullscreen ? "compress" : "expand"} onClick={onClick} />
            }
        }
        const toggleButton = (isOpen, isFullscreen, onClick) => {
            if (!isFullscreen) {
                return <IconButton icon={isOpen ? "chevron-circle-right" : "chevron-circle-left"} onClick={onClick} />
            }
        }

		const activeContentDimensions = useSelector(selectors.CR.ContentDimensions.active);
		const interfaceLanguage = useSelector((state) => state?.user?.preferences?.interfaceLanguage);
        const iframeSrc = new URL('https://api.neosidekick.com/chat/');
        iframeSrc.searchParams.append('contentLanguage', activeContentDimensions.language ? activeContentDimensions.language[0] : "");
        iframeSrc.searchParams.append('interfaceLanguage', interfaceLanguage);
        iframeSrc.searchParams.append('userId', configuration.userId);
        iframeSrc.searchParams.append('plattform', 'neos');
        iframeSrc.searchParams.append('domain', configuration.domain);
        iframeSrc.searchParams.append('siteName', configuration.siteName)
        if (configuration?.referral) {
            iframeSrc.searchParams.append('referral', configuration?.referral);
        }
        if (configuration?.apikey) {
            iframeSrc.searchParams.append('apikey', configuration?.apikey);
        }

        return <div className={`neosidekick_appWrapper ${isOpen ? "neosidekick_appWrapper--sidebar-open" : ""}  ${isFullscreen ? "neosidekick_appWrapper--sidebar-fullscreen" : ""}`}>
			<App {...props} />
            <div className="neosidekick_sideBar">
				<div className="neosidekick_sideBar__title">
					<Headline className={`neosidekick_sideBar__title-headline ${isOpen ? "neosidekick_sideBar__title-headline--open" : ""}`}>AI Sidekick</Headline>
					<div>
                        {fullscreenButton(isOpen, isFullscreen, () => setFullscreenAndPersistState(!isFullscreen))}
                        {toggleButton(isOpen, isFullscreen, () => setOpenAndPersistState(!isOpen))}
                    </div>
				</div>
                <iframe className={`neosidekick_sideBar__frame ${isOpen ? "neosidekick_sideBar__frame--open" : ""}`} src={iframeSrc.toString()} />
            </div>
		</div>
	}
	containerRegistry.set('App', WrappedApp);
});

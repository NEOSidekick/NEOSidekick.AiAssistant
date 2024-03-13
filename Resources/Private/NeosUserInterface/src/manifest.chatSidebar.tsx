import React from "react";
import {Headline, IconButton} from "@neos-project/react-ui-components";
import {useSelector} from "react-redux";
import {selectors} from "@neos-project/neos-ui-redux-store";
import {GlobalRegistry} from "@neos-project/neos-ts-interfaces";

export default (globalRegistry: GlobalRegistry, configuration: object) => {
    const containerRegistry = globalRegistry.get('containers');
    const App = containerRegistry.get('App');
    const WrappedApp = (props: Record<string, unknown>) => {
        // @ts-ignore
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
                <iframe id="neosidekickAssistant" className={`neosidekick_sideBar__frame ${isOpen ? "neosidekick_sideBar__frame--open" : ""}`} src={iframeSrc.toString()} allow="clipboard-write" onLoad={(e) => e.target.dataset.loaded = true} />
            </div>
        </div>
    }
    containerRegistry.set('App', WrappedApp);
}

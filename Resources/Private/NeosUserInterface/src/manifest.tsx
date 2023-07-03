// @ts-ignore
import manifest, {SynchronousRegistry} from "@neos-project/neos-ui-extensibility";
// @ts-ignore
import { IconButton, Headline } from "@neos-project/react-ui-components";
// @ts-ignore
import { actionTypes, selectors } from '@neos-project/neos-ui-redux-store';
// @ts-ignore
import React from 'react';
// @ts-ignore
import { useSelector } from 'react-redux';
// @ts-ignore
import { takeLatest } from 'redux-saga/effects';
import MagicTextFieldEditor from './Editors/MagicTextFieldEditor';
import MagicTextAreaEditor from './Editors/MagicTextAreaEditor';
import "./style.css";
import {createExternalService} from './ExternalService';
import {createContentService} from './ContentService';
import {createWatchNodeCreatedSaga} from "./Sagas/NodeCreated";
import {createAssistantService} from "./Service/AssistantService";

export default function delay(timeInMilliseconds: number): Promise<void> {
    // @ts-ignore
    return new Promise(resolve => setTimeout(resolve, timeInMilliseconds));
}

manifest("NEOSidekick.AiAssistant", {}, (globalRegistry, {store, frontendConfiguration}) => {
    const configuration = frontendConfiguration['NEOSidekick.AiAssistant'];
    if (configuration === null || configuration.enabled === false) {
        return
    }

    globalRegistry.set('NEOSidekick.AiAssistant', new SynchronousRegistry(`test`))
    const externalService = createExternalService(frontendConfiguration);
    globalRegistry.get('NEOSidekick.AiAssistant').set('externalService', externalService)
    const contentService = createContentService(globalRegistry, store);
    globalRegistry.get('NEOSidekick.AiAssistant').set('contentService', contentService)
    const assistantService = createAssistantService(globalRegistry, store)
    globalRegistry.get('NEOSidekick.AiAssistant').set('assistantService', assistantService)

    assistantService.listenToMessages()

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
        const iframeSrc = new URL(`${configuration.apiDomain}/chat/`);
        iframeSrc.searchParams.append('contentLanguage', activeContentDimensions.language ? activeContentDimensions.language[0] : "");
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

    let requiredChangedEvent = false
    const watchDocumentNodeChange = function * () {
        yield takeLatest([actionTypes.UI.ContentCanvas.SET_SRC, actionTypes.UI.ContentCanvas.RELOAD, actionTypes.CR.Nodes.MERGE], function * (action) {
            if (action.type === actionTypes.UI.ContentCanvas.SET_SRC) {
                requiredChangedEvent = true;
            }
            yield delay(500)

            const nodeTypesRegistry = globalRegistry.get('@neos-project/neos-ui-contentrepository')
            const state = store.getState();

            const guestFrame = document.getElementsByName('neos-content-main')[0];
            // @ts-ignore
            const guestFrameDocument = guestFrame?.contentDocument;

            const previewUrl = state?.ui?.contentCanvas?.previewUrl

            const currentDocumentNode = contentService.getCurrentDocumentNode()
            const currentDocumentNodePath = currentDocumentNode?.contextPath
            // @ts-ignore
            const relevantNodes = Object.values(state?.cr?.nodes?.byContextPath || {}).filter(node => {
                const documentRole = nodeTypesRegistry.getRole('document');
                if (!documentRole) {
                    throw new Error('Document role is not loaded!');
                }
                const documentSubNodeTypes = nodeTypesRegistry.getSubTypesOf(documentRole);
                // only get nodes that are children of the current document node
                return currentDocumentNodePath &&
                    node.contextPath.indexOf(currentDocumentNodePath.split('@')[0]) === 0 &&
                    (node.contextPath === currentDocumentNodePath || !documentSubNodeTypes.includes(node.nodeType))
            })

            assistantService.sendMessageToIframe({
                version: '1.0',
                eventName: requiredChangedEvent ? 'page-changed' : 'page-updated',
                data: {
                    'url': previewUrl,
                    'title': currentDocumentNode?.properties?.title || guestFrameDocument?.title,
                    'content': guestFrameDocument?.body?.innerHTML,
                    'structuredContent': relevantNodes,
                    'targetAudience': contentService.getCurrentDocumentTargetAudience(),
                    'pageBriefing': contentService.getCurrentDocumentPageBriefing(),
                    'focusKeyword': contentService.getCurrentDocumentFocusKeyword()
                },
            })
            requiredChangedEvent = false;
        });
    }

    const sagasRegistry = globalRegistry.get('sagas')
    sagasRegistry.set('NEOSidekick.AiAssistant/watchDocumentNodeChange', {saga: watchDocumentNodeChange})
    sagasRegistry.set('NEOSidekick.AiAssistant/watchNodeCreated', {saga: createWatchNodeCreatedSaga(globalRegistry, store)})

    const editorsRegistry = globalRegistry.get('inspector').get('editors');
    editorsRegistry.set('NEOSidekick.AiAssistant/Inspector/Editors/MagicTextFieldEditor', {
        component: MagicTextFieldEditor
    });
    editorsRegistry.set('NEOSidekick.AiAssistant/Inspector/Editors/MagicTextAreaEditor', {
        component: MagicTextAreaEditor
    });
});

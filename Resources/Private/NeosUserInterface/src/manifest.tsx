import manifest, {SynchronousRegistry} from "@neos-project/neos-ui-extensibility";

import {createExternalService} from './Service/ExternalService';
import {createContentService} from './Service/ContentService';

import initializeEditor from './manifest.editors';
import initializeChatSidebar from './manifest.chatSidebar';
import initializeWatchPageContent from './manifest.watchPageContent';
import initializeRichToolbarIcon from './manifest.richToolbarIcon';

import "./manifest.chatSidebar.css";
import {createContentCanvasService} from "./Service/ContentCanvasService";
import {createIFrameApiService} from "./Service/IFrameApiService";

manifest("NEOSidekick.AiAssistant", {}, (globalRegistry, {store, frontendConfiguration}) => {
    const configuration = frontendConfiguration['NEOSidekick.AiAssistant'];
    const enabled = !!configuration && configuration.enabled !== false;
    initializeEditor(globalRegistry, enabled);

    if (!enabled) {
        return;
    }

    if (!configuration.hasOwnProperty('defaultLanguage') || !configuration['defaultLanguage']) {
        console.error('Could not initialize AiAssistant: defaultLanguage is not configured correctly, see README.')
        return;
    }

    // initialize services
    globalRegistry.set('NEOSidekick.AiAssistant', new SynchronousRegistry(""));
    const neosidekickRegistry = globalRegistry.get('NEOSidekick.AiAssistant');
    neosidekickRegistry.set('configuration', configuration);
    const externalService = createExternalService(frontendConfiguration);
    neosidekickRegistry.set('externalService', externalService);
    const contentService = createContentService(globalRegistry, store);
    neosidekickRegistry.set('contentService', contentService);
    const iFrameApiService = createIFrameApiService(globalRegistry, store);
    neosidekickRegistry.set('iFrameApiService', iFrameApiService);
    const contentCanvasService = createContentCanvasService(globalRegistry, store, iFrameApiService);
    neosidekickRegistry.set('contentCanvasService', contentCanvasService);

    initializeChatSidebar(globalRegistry, configuration);
    initializeWatchPageContent(globalRegistry, store, iFrameApiService, contentService);
    initializeRichToolbarIcon(globalRegistry);
});

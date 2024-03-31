import manifest, {SynchronousMetaRegistry, SynchronousRegistry} from "@neos-project/neos-ui-extensibility";

import {SidekickFrontendConfiguration} from "./interfaces";
import {createApiService} from './Service/ApiService';
import {createContentService} from './Service/ContentService';
import {createContentCanvasService} from "./Service/ContentCanvasService";
import {createIFrameApiService} from "./Service/IFrameApiService";
import {reducer} from './actions';

import initializeEditor from './manifest.editors';
import initializeChatSidebar from './manifest.chatSidebar';
import initializeWatchPageContent from './manifest.watchPageContent';
import initializeRichToolbarIcon from './manifest.richToolbarIcon';

import "./manifest.chatSidebar.css";

manifest("NEOSidekick.AiAssistant", {}, (globalRegistry: SynchronousMetaRegistry<any>, {store, frontendConfiguration}) => {
    const configuration = frontendConfiguration['NEOSidekick.AiAssistant'] as SidekickFrontendConfiguration;
    initializeEditor(globalRegistry, configuration?.enabled);

    if (!configuration?.enabled) {
        return;
    }

    if (!configuration.hasOwnProperty('defaultLanguage') || !configuration['defaultLanguage']) {
        console.error('Could not initialize AiAssistant: defaultLanguage is not configured correctly, see README.')
        return;
    }

    globalRegistry.get('reducers').set('NEOSidekick.AiAssistant', { reducer });

    // initialize services
    globalRegistry.set('NEOSidekick.AiAssistant', new SynchronousRegistry(""));
    const neosidekickRegistry = globalRegistry.get('NEOSidekick.AiAssistant');
    neosidekickRegistry.set('configuration', configuration);
    const externalService = createApiService(configuration);
    neosidekickRegistry.set('externalService', externalService);
    const contentService = createContentService(globalRegistry, store);
    neosidekickRegistry.set('contentService', contentService);
    const iFrameApiService = createIFrameApiService();
    neosidekickRegistry.set('iFrameApiService', iFrameApiService);
    const contentCanvasService = createContentCanvasService(globalRegistry, store, iFrameApiService);
    neosidekickRegistry.set('contentCanvasService', contentCanvasService);

    initializeChatSidebar(globalRegistry, configuration);
    initializeWatchPageContent(globalRegistry, store, iFrameApiService, contentService);
    initializeRichToolbarIcon(globalRegistry);
});

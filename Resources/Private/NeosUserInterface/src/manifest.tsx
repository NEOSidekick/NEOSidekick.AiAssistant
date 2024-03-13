import manifest, {SynchronousRegistry} from "@neos-project/neos-ui-extensibility";

import {createExternalService} from './Service/ExternalService';
import {createContentService} from './Service/ContentService';
import {createAssistantService} from "./Service/AssistantService";

import initializeEditor from './manifest.editors';
import initializeChatSidebar from './manifest.chatSidebar';
import initializeWatchPageContent from './manifest.watchPageContent';
import initializeRichToolbarIcon from './manifest.richToolbarIcon';

import "./manifest.chatSidebar.css";

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
    globalRegistry.set('NEOSidekick.AiAssistant', new SynchronousRegistry(""))
    globalRegistry.get('NEOSidekick.AiAssistant').set('configuration', configuration)
    const externalService = createExternalService(frontendConfiguration);
    globalRegistry.get('NEOSidekick.AiAssistant').set('externalService', externalService)
    const contentService = createContentService(globalRegistry, store);
    globalRegistry.get('NEOSidekick.AiAssistant').set('contentService', contentService)
    const assistantService = createAssistantService(globalRegistry, store)
    globalRegistry.get('NEOSidekick.AiAssistant').set('assistantService', assistantService)
    assistantService.listenToMessages()

    initializeChatSidebar(globalRegistry, configuration);
    initializeWatchPageContent(globalRegistry, store, assistantService, contentService);
    initializeRichToolbarIcon(globalRegistry);
});

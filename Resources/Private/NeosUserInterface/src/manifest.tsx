import manifest, {SynchronousMetaRegistry, SynchronousRegistry} from "@neos-project/neos-ui-extensibility";
import {fetchWithErrorHandling} from "@neos-project/neos-ui-backend-connector";

import {SidekickFrontendConfiguration} from "./interfaces";
import {createSilentAuthorizationService, createSilentAuthorizeHandler} from './Service/silentAuthorization';
import {createEmbedTokenRequestHandler, fetchEmbedToken} from './Service/embedToken';
import {createApiService} from './Service/ApiService';
import {createContentService} from './Service/ContentService';
import {createContentCanvasService} from "./Service/ContentCanvasService";
import {createIFrameApiService} from "./Service/IFrameApiService";
import {ContentTreeService} from "./Service/ContentTreeService";
import {reducer} from './actions';

import initializeEditor from './manifest.editors';
import initializeChatSidebar from './manifest.chatSidebar';
import initializeWatchPageContent from './manifest.watchPageContent';
import initializeRichToolbarIcon from './manifest.richToolbarIcon';
import {createPreloadContentTreeSaga} from './Sagas/PreloadContentTree';

import "./manifest.chatSidebar.css";

interface IframeIncomingMessage {
    origin: string;
    source: {postMessage: (message: object, targetOrigin: string) => void} | null;
    data?: {
        eventName?: unknown;
        type?: unknown;
        requestId?: unknown;
        data?: {
            state?: unknown;
        };
    };
}

// Coalesces duplicate triggers for the same state (e.g. React StrictMode double-invoking the iframe
// effect in dev, or the iframe re-asking) onto one in-flight do-authorize, while still answering
// every trigger.
const silentAuthorizationService = createSilentAuthorizationService({fetcher: fetchWithErrorHandling});

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
    const assistantFrameOrigin = new URL(configuration.apiDomain).origin;
    const iFrameApiService = createIFrameApiService(assistantFrameOrigin);
    neosidekickRegistry.set('iFrameApiService', iFrameApiService);
    const contentCanvasService = createContentCanvasService(globalRegistry, store, iFrameApiService);
    neosidekickRegistry.set('contentCanvasService', contentCanvasService);
    const nodeTypesRegistry = globalRegistry.get('@neos-project/neos-ui-contentrepository');
    const contentTreeService = new ContentTreeService(store, nodeTypesRegistry);
    neosidekickRegistry.set('contentTreeService', contentTreeService);

    // A duplicate trigger must never be dropped silently: the service shares the in-flight attempt
    // for a given state, so no second do-authorize is fired against an already-consumed state, but
    // every trigger is answered once that attempt settles. Without an answer the iframe's poller
    // would wait out its full budget.
    const handleSilentAuthorize = createSilentAuthorizeHandler({
        authorize: (state: string) => silentAuthorizationService.authorize(state),
        notifyResult: iFrameApiService.notifySilentAuthorizationResult,
    });

    // On-demand embed-token issuance for the assistant's re-bootstrap. Registered once at
    // plugin boot inside the single listenToMessages listener (idempotent across iframe
    // re-renders), which already verifies event.source === the assistant iframe's
    // contentWindow AND event.origin === assistantFrameOrigin before dispatching here;
    // the handler replies via event.source.postMessage with event.origin as the explicit
    // target origin.
    const handleEmbedTokenRequest = createEmbedTokenRequestHandler({
        fetchToken: (options) => fetchEmbedToken(options),
    });

    iFrameApiService.listenToMessages((message: IframeIncomingMessage) => {
        if (handleEmbedTokenRequest(message)) {
            return;
        }

        const eventName = message.data?.eventName;

        if (eventName === 'get-content-tree') {
            const contentTree = contentTreeService.getDocumentContentTree();
            iFrameApiService.respondWithContentTree(contentTree);
            return;
        }

        handleSilentAuthorize(message);
    });

    const sagasRegistry = globalRegistry.get('sagas');
    sagasRegistry.set('NEOSidekick.AiAssistant/preloadContentTree', {
        saga: createPreloadContentTreeSaga(contentTreeService)
    });

    // Expose to window for browser console testing
    (window as any).__neosidekick_contentTreeService = contentTreeService;

    initializeChatSidebar(globalRegistry, configuration);
    initializeWatchPageContent(globalRegistry, store, iFrameApiService, contentService);
    initializeRichToolbarIcon(globalRegistry);
});

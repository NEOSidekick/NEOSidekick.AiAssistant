import manifest, {SynchronousMetaRegistry, SynchronousRegistry} from "@neos-project/neos-ui-extensibility";

import {SidekickFrontendConfiguration} from "./interfaces";
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
    data?: {
        eventName?: unknown;
        data?: {
            state?: unknown;
        };
    };
}

const SILENT_AUTHORIZE_EVENT = 'neosidekick-silent-authorize';

// Guards against a duplicate trigger (e.g. React StrictMode double-invoking the iframe effect in
// dev) firing a second do-authorize against an already-consumed state. The parent page is not
// reloaded by the silent flow, so this resets once the in-flight request settles.
let silentAuthorizationInProgress = false;

/**
 * Performs a silent re-authorization: the iframe (which has detected prior consent but a stale
 * session) asks the Neos backend to mint a fresh JWT from the live backend session. This is a
 * same-origin, CSRF-protected POST carrying the live session cookie; on success Neos forwards the
 * token to Laravel's callback keyed by `state`.
 */
const performSilentAuthorization = async (state: string, csrfToken: string): Promise<boolean> => {
    try {
        const response = await fetch('/neosidekick/agent/do-authorize.json', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
            },
            body: new URLSearchParams({
                __csrfToken: csrfToken,
                state,
            }).toString(),
        });

        return response.ok;
    } catch (error) {
        console.error('NEOSidekick silent authorization failed', error);
        return false;
    }
};

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

    iFrameApiService.listenToMessages((message: IframeIncomingMessage) => {
        const eventName = message.data?.eventName;

        if (eventName === 'get-content-tree') {
            const contentTree = contentTreeService.getDocumentContentTree();
            iFrameApiService.respondWithContentTree(contentTree);
            return;
        }

        if (eventName === SILENT_AUTHORIZE_EVENT) {
            const state = message.data?.data?.state;
            if (typeof state !== 'string' || state === '') {
                iFrameApiService.notifySilentAuthorizationResult(false);
                return;
            }

            if (silentAuthorizationInProgress) {
                return; // a re-auth is already running; don't fire a duplicate do-authorize
            }
            silentAuthorizationInProgress = true;

            performSilentAuthorization(state, configuration.csrfToken).then((succeeded) => {
                silentAuthorizationInProgress = false;
                iFrameApiService.notifySilentAuthorizationResult(succeeded);
            });
        }
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

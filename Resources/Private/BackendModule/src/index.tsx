import React from 'react';
import RootView from './Components/Views/RootView'
import createStore from "./Store";
import {createRoot} from 'react-dom/client';
import {Endpoints} from "./Model/Endpoints";
import BackendService from "./Service/BackendService";
import TranslationService from "./Service/TranslationService";
import {ExternalService} from "./Service/ExternalService";
import {isNull, omitBy} from "lodash"
import defaultModuleConfiguration from "./Util/defaultModuleConfiguration";
import {AppState} from "./Enums/AppState";
import {ListState} from "./Enums/ListState";
import {Provider} from "react-redux";

document.addEventListener('DOMContentLoaded', async() => {
    const endpoints: Endpoints = window['_NEOSIDEKICK_AIASSISTANT_endpoints']
    const configuration: {
        apiDomain: string,
        apiKey: string,
        defaultLanguage: string,
        altTextGeneratorModule: object|null,
        userInterfaceLanguage: string
    } = window['_NEOSIDEKICK_AIASSISTANT_configuration']

    const appContainer = document.getElementById('appContainer');
    const scope = appContainer.dataset.scope;

    const backend = BackendService.getInstance()
    backend.configure(endpoints, appContainer.dataset.csrfToken)

    const translationService = TranslationService.getInstance()
    const translations = await backend.getTranslations()
    translationService.configure(translations)

    const root = createRoot(appContainer)

    if (!endpoints || !configuration) {
        root.render(
            <p dangerouslySetInnerHTML={{ __html: translationService.translate('NEOSidekick.AiAssistant:AssetModule:error.configuration', 'This module is not configured correctly. Please consult the documentation!') }} />
        )
        return
    }

    if (!configuration.apiKey) {
        root.render(
            <p dangerouslySetInnerHTML={{ __html: translationService.translate('NEOSidekick.AiAssistant:AssetModule:error.noApiKey', 'This feature is not available in the free version!') }} />
        )
        return
    }

    const externalService = ExternalService.getInstance()
    externalService.configure(configuration.apiDomain, configuration.apiKey, configuration.userInterfaceLanguage)

    let availableNodeTypeFilters = null
    if (scope === 'focusKeywordGeneratorModule') {
        availableNodeTypeFilters = {}
        const nodeTypeSchema: Partial<{nodeTypes: object}> = await backend.getNodeTypeSchema()
        let availableNodeTypes = nodeTypeSchema.nodeTypes
        Object.keys(availableNodeTypes).map(nodeType => {
            const nodeTypeDefinition: {superTypes: object, ui: {label: string}} = availableNodeTypes[nodeType]
            if (nodeTypeDefinition?.superTypes?.hasOwnProperty('NEOSidekick.AiAssistant:Mixin.AiPageBriefing')) {
                availableNodeTypeFilters[nodeType] = translationService.translate(nodeTypeDefinition.ui.label, nodeTypeDefinition.ui.label)
            }
        })
    }

    // Set default configuration
    const initialModuleConfiguration = omitBy(configuration[scope] || {}, isNull);
    const moduleConfiguration = {
        limit: 5,
        language: configuration.defaultLanguage,
        ...defaultModuleConfiguration[scope],
        ...initialModuleConfiguration
    }
    const store = createStore({
        app: {
            moduleConfiguration,
            initialModuleConfiguration,
            scope,
            appState: AppState.Configure,
            listState: ListState.Loading,
            availableNodeTypeFilters
        }
    })

    root.render(
        <Provider store={store}>
            <RootView endpoints={endpoints} />
        </Provider>
    )
})

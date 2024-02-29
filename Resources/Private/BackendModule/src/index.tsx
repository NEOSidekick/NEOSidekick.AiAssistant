import React from 'react';
import {Root} from './Components'
import createStore from "./Store";
import { createRoot } from 'react-dom/client';
import EndpointsInterface from "./Model/EndpointsInterface";
import BackendService from "./Service/BackendService";
import TranslationService from "./Service/TranslationService";
import {ExternalService} from "./Service/ExternalService";
import {omitBy, isNull} from "lodash"
document.addEventListener('DOMContentLoaded', async() => {
    const endpoints: EndpointsInterface = window['_NEOSIDEKICK_AIASSISTANT_endpoints']
    const configuration: {
        apiDomain: string,
        apiKey: string,
        defaultLanguage: string,
        altTextGeneratorModule: object|null
    } = window['_NEOSIDEKICK_AIASSISTANT_configuration']

    const backend = BackendService.getInstance()
    backend.configure(endpoints)

    const translationService = TranslationService.getInstance()
    const translations = await backend.getTranslations()
    translationService.configure(translations)

    const root = createRoot(document.getElementById('appContainer'))

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
    externalService.configure(configuration.apiDomain, configuration.apiKey)

    // Set default configuration
    const initialModuleConfiguration = omitBy(configuration?.altTextGeneratorModule || {}, isNull);
    const moduleConfiguration = {
        onlyAssetsInUse: false,
        propertyName: 'title',
        limit: 5,
        language: configuration.defaultLanguage,
        ...initialModuleConfiguration
    }
    const store = createStore({
        app: {
            moduleConfiguration,
            initialModuleConfiguration,
            loading: true,
            started: false,
            busy: false,
            items: {}
        }
    })

    root.render(
        <Root store={store} />
    )
})

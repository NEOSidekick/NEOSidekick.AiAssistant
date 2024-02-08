import React from 'react';
import {Root} from './Components'
import createStore from "./Store";
import { createRoot } from 'react-dom/client';
import EndpointsInterface from "./Model/EndpointsInterface";
import BackendService from "./Service/BackendService";
import TranslationService from "./Service/TranslationService";
import {ExternalService} from "./Service/ExternalService";
document.addEventListener('DOMContentLoaded', async() => {
    const endpoints: EndpointsInterface = window['_NEOSIDEKICK_AIASSISTANT_endpoints']
    const configuration = window['_NEOSIDEKICK_AIASSISTANT_configuration']

    if (!endpoints || !configuration) {
        throw new Error('Failed loading configuration!')
    }

    const backend = BackendService.getInstance()
    backend.configure(endpoints)

    const translationService = TranslationService.getInstance()
    const translations = await backend.getTranslations()
    translationService.configure(translations)

    const externalService = ExternalService.getInstance()
    externalService.configure(configuration.apiDomain, configuration.apiKey)

    // Set default configuration
    const moduleConfiguration = {
        onlyAssetsInUse: false,
        propertyName: 'title',
        limit: 5,
        ...configuration?.altTextGeneratorModule
    }
    const store = createStore({
        app: {
            moduleConfiguration,
            loading: true,
            started: false,
            busy: false,
            items: {}
        }
    })

    const root = createRoot(document.getElementById('appContainer'))
    root.render(
        <Root store={store} />
    )
})

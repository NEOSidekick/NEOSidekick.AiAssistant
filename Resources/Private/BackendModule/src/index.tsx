import React from 'react';
import RootView from './Views/RootView'
import {createRoot} from 'react-dom/client';
import {Endpoints} from "./Model/Endpoints";
import NeosBackendService from "./Service/NeosBackendService";
import TranslationService from "./Service/TranslationService";
import {SidekickApiService} from "./Service/SidekickApiService";
import {ModuleConfiguration} from "./Model/ModuleConfiguration";
import Alert from "./Components/Alert";
import Workspaces from "./Model/Workspaces";

document.addEventListener('DOMContentLoaded', async() => {
    const csrfToken = window['_NEOSIDEKICK_AIASSISTANT_csrfToken'];
    const endpoints: Endpoints = window['_NEOSIDEKICK_AIASSISTANT_endpoints']
    const frontendConfiguration: {
        apiDomain: string,
        apiKey: string,
        defaultLanguage: string,
        userInterfaceLanguage: string,
        domain: string,
        contentDimensions: {
            [dimensionName: string]: {
                label: string,
                icon: string,
                default: string,
                presets: {
                    [preset: string]: {
                        label: string,
                        values: string[],
                        uriSegment: string
                    }
                }
            }
        },
        syncLanguagePresets?: string[]
    } = window['_NEOSIDEKICK_AIASSISTANT_frontendConfiguration'];
    const moduleConfiguration = window['_NEOSIDEKICK_AIASSISTANT_moduleConfiguration'] as ModuleConfiguration;
    const workspaces = window['_NEOSIDEKICK_AIASSISTANT_workspaces'] as Workspaces;

    const backend = NeosBackendService.getInstance()
    backend.configure(endpoints, csrfToken)

    const translationService = TranslationService.getInstance();
    const translations = await backend.getTranslations();
    translationService.configure(translations);

    const appContainer = document.getElementById('appContainer');
    const root = createRoot(appContainer)

    if (!csrfToken || !endpoints || !frontendConfiguration || !moduleConfiguration || !workspaces) {
        root.render(
            <Alert message={translationService.translate('NEOSidekick.AiAssistant:Module:error.configuration', 'This module is not configured correctly. Please consult the documentation!')} />
        )
        return
    }

    if (!frontendConfiguration.apiKey) {
        root.render(
            <Alert message={translationService.translate('NEOSidekick.AiAssistant:Module:error.noApiKey', 'This feature is not available in the free version!')} />
        )
        return
    }

    const sidekickApiService = SidekickApiService.getInstance()
    sidekickApiService.configure(frontendConfiguration.apiDomain, frontendConfiguration.apiKey, frontendConfiguration.userInterfaceLanguage)

    root.render(
        <RootView
            endpoints={endpoints}
            moduleConfiguration={moduleConfiguration}
            workspaces={workspaces}
            languageDimensionConfiguration={frontendConfiguration.contentDimensions.language ?? null}
            syncLanguagePresets={frontendConfiguration.syncLanguagePresets ?? []}
            domain={frontendConfiguration.domain}
        />
    )
})

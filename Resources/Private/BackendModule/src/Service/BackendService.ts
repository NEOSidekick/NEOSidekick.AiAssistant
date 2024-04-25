import {Endpoints} from "../Model/Endpoints";
import AiAssistantError from "./AiAssistantError";
import {ModuleConfiguration} from "../Model/ModuleConfiguration";
import {AssetFilters, DocumentNodeFilters, Filters} from "../Dto/Filters";
import {FocusKeywordModuleConfiguration} from "../Model/FocusKeywordModuleConfiguration";
import {AssetModuleConfiguration} from "../Model/AssetModuleConfiguration";

export default class BackendService {
    private static instance: BackendService | null = null;
    private endpoints: Endpoints;
    private csrfToken: string;

    public static getInstance(): BackendService {
        if (!BackendService.instance) {
            BackendService.instance = new BackendService();
        }
        return BackendService.instance
    }

    public configure(endpoints: Endpoints, csrfToken: string) {
        this.endpoints = endpoints
        this.csrfToken = csrfToken
    }

    public async getItems(moduleConfiguration: ModuleConfiguration)
    {
        const params = new URLSearchParams();
        const filters = this.toFilterDto(moduleConfiguration);
        Object.keys(filters).map(key => params.append(`configuration[${key}]`, filters[key]))
        const response = await fetch(this.endpoints.get + '?' + params.toString(), {
            credentials: 'include'
        });
        if (response.status < 200 || response.status >= 400) {
            throw new AiAssistantError('An error occurred while fetching the items that need processing', '1709650151037', await response.text())
        }
        return await response.json()
    }

    private toFilterDto(moduleConfiguration: ModuleConfiguration): Filters {
        switch (moduleConfiguration.itemType) {
            case 'Asset':
                const {onlyAssetsInUse} = moduleConfiguration as AssetModuleConfiguration;
                // TODO Refactor PHP DTO
                return {onlyAssetsInUse, propertyName: 'title', language: 'de', firstResult:0, limit: 1000} as AssetFilters;
            case 'DocumentNode':
                const {workspace, mode, nodeTypeFilter} = moduleConfiguration as FocusKeywordModuleConfiguration;
                return {workspace, mode, nodeTypeFilter: nodeTypeFilter || '', firstResult:0, limit: 1000} as DocumentNodeFilters;
            default:
                throw new Error('Unknown item type');
        }
    }

    public async persistItems(updateItems: object[])
    {
        const response = await fetch(this.endpoints.update, {
            method: 'POST',
            headers: {
                'X-Flow-Csrftoken': this.csrfToken,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({updateItems})
        })
        if (response.status < 200 || response.status >= 400) {
            throw new AiAssistantError('An error occurred while persisting items', '1709648035592')
        }
        return await response.json()
    }

    public async getNodeTypeSchema()
    {
        const response = await fetch(this.endpoints.nodeTypeSchema, {
            credentials: 'include'
        })
        return await response.json()
    }

    public async getTranslations()
    {
        const response = await fetch(this.endpoints.translations, {
            credentials: 'include'
        })
        return await response.json()
    }
}

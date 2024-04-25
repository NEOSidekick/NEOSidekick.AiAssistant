import {Endpoints} from "../Model/Endpoints";
import AiAssistantError from "./AiAssistantError";
import {isNull, omitBy} from "lodash"
import {ModuleConfiguration} from "../Model/ModuleConfiguration";

export default class BackendService {
    private static instance: BackendService | null = null;
    private endpoints: object;
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

    public async getItems(configuration: ModuleConfiguration)
    {
        const params = new URLSearchParams()
        Object.keys(omitBy(configuration || {}, isNull)).map(key => params.append(`configuration[${key}]`, configuration[key]))
        const response = await fetch(this.endpoints.get + '?' + params.toString(), {
            credentials: 'include'
        });
        if (response.status < 200 || response.status >= 400) {
            throw new AiAssistantError('An error occurred while fetching the items that need processing', '1709650151037', await response.text())
        }
        return await response.json()
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

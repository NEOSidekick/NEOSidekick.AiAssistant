import EndpointsInterface from "../Model/EndpointsInterface";
import BackendAssetModuleResultDtoInterface from "../Model/BackendAssetModuleResultDtoInterface";
import AiAssistantError from "./AiAssistantError";

export default class BackendService {
    private static instance: BackendService | null = null;
    private endpoints: object;
    public static getInstance(): BackendService {
        if (!BackendService.instance) {
            BackendService.instance = new BackendService();
        }
        return BackendService.instance
    }

    public configure(endpoints: EndpointsInterface) {
        this.endpoints = endpoints
    }

    public *getAssetsThatNeedProcessing(configuration: BackendAssetModuleResultDtoInterface)
    {
        const params = new URLSearchParams()
        Object.keys(configuration).map(key => params.append(`configuration[${key}]`, configuration[key]))
        const response = yield fetch(this.endpoints.getAssets + '?' + params.toString(), {
            credentials: 'include'
        });
        if (response.status < 200 || response.status >= 400) {
            throw new AiAssistantError('An error occurred while fetching the assets', '1709650151037')
        }
        return yield response.json()
    }

    public async persistAssets(assets: object[])
    {
        const response = await fetch(this.endpoints.updateAssets, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({resultDtos: assets})
        })
        if (response.status < 200 || response.status >= 400) {
            throw new AiAssistantError('An error occurred while persisting an asset', '1709648035592')
        }
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

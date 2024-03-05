import EndpointsInterface from "../Model/EndpointsInterface";
import BackendAssetModuleResultDtoInterface from "../Model/BackendAssetModuleResultDtoInterface";

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

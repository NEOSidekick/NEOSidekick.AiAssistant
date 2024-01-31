import EndpointsInterface from "../Model/EndpointsInterface";

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

    public async getAssetsThatNeedProcessing()
    {
        const response = await fetch(this.endpoints.getAssets, {
            credentials: 'include'
        });
        return await response.json()
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

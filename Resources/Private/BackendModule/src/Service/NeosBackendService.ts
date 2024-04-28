import {Endpoints} from "../Model/Endpoints";
import AiAssistantError from "../AiAssistantError";
import {
    AssetModuleConfiguration,
    DocumentNodeModuleConfiguration,
    ModuleConfiguration
} from "../Model/ModuleConfiguration";
import {FindAssetsFilter, FindDocumentNodesFilter, FindFilter} from "../Dto/FindFilter";

export default class NeosBackendService {
    private static instance: NeosBackendService | null = null;
    private endpoints: Endpoints;
    private csrfToken: string;
    private previewContentQueue: {isFetching: boolean, uri: string, promise: Promise<string>, resolve: (value: string) => void, reject: (reason?: any) => void}[] = [];

    public static getInstance(): NeosBackendService {
        if (!NeosBackendService.instance) {
            NeosBackendService.instance = new NeosBackendService();
        }
        return NeosBackendService.instance
    }

    public configure(endpoints: Endpoints, csrfToken: string) {
        this.endpoints = endpoints
        this.csrfToken = csrfToken
    }

    public async fetchPreviewContent(uri: string): Promise<string> {
        const itemWithSameUri = this.previewContentQueue.find(item => item.uri === uri);
        if (itemWithSameUri) {
            return itemWithSameUri.promise;
        }
        let resolve: (value: string | PromiseLike<string>) => void, reject: (reason?: any) => void;
        const promise = new Promise<string>((localResolve, localReject) => {
            resolve = localResolve;
            reject = localReject;
        });
        this.previewContentQueue.push({isFetching: false, uri, promise, resolve, reject});
        this.fetchNextPreviewContents().then(() => {});
        return promise;
    }

    private async fetchNextPreviewContents() {
        // we want to resolve a maximum of 3 promises at a time
        for (let i = 0; i < this.previewContentQueue.length && i < 3; i++) {
            const {isFetching, uri, resolve, reject} = this.previewContentQueue[i];
            if (!isFetching) {
                this.previewContentQueue[i].isFetching = true;
                fetch(uri)
                    .then(response => response.text())
                    .then(text => resolve(text))
                    .catch(reject)
                    .finally(() => {
                        this.previewContentQueue = this.previewContentQueue.filter(item => item.uri !== uri);
                        this.fetchNextPreviewContents();
                    });
            }
        }
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

    private toFilterDto(moduleConfiguration: ModuleConfiguration): FindFilter {
        switch (moduleConfiguration.itemType) {
            case 'Asset':
                const {onlyAssetsInUse, propertyName} = moduleConfiguration as AssetModuleConfiguration;
                return {onlyAssetsInUse, propertyNameMustBeEmpty: propertyName, firstResult:0, limit: 10000} as FindAssetsFilter;
            case 'DocumentNode':
                const {workspace, mode, nodeTypeFilter} = moduleConfiguration as DocumentNodeModuleConfiguration;
                return {workspace, mode, nodeTypeFilter: nodeTypeFilter || ''} as FindDocumentNodesFilter;
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

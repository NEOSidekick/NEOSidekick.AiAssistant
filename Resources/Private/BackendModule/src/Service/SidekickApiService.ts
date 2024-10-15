import AiAssistantError from "../AiAssistantError";

interface fetchGenerateQueueItem {
    isFetching: boolean;
    promiseId: string;
    module: string;
    language: string;
    user_input: object;
    promise: Promise<string>;
    resolve: (value: string) => void;
    reject: (reason?: any) => void;
}

export class SidekickApiService {
    private static instance: SidekickApiService | null = null;
    private apiDomain: string = ''
    private apiKey: string = ''
    private interfaceLanguage: string = 'en';
    private fetchGenerateQueue: fetchGenerateQueueItem[] = [];

    constructor() {
    }

    public static getInstance(): SidekickApiService
    {
        if (!SidekickApiService.instance) {
            SidekickApiService.instance = new SidekickApiService();
        }
        return SidekickApiService.instance
    }

    public configure = (apiDomain: string, apiKey: string, interfaceLanguage: string) => {
        this.apiDomain = apiDomain
        this.apiKey = apiKey
        this.interfaceLanguage = interfaceLanguage
    }

    hasApiKey = () => {
        return this.apiKey !== null && this.apiKey !== ''
    }

    generate = async (module: string, language: string, user_input: object = {}) => {
        if (!this.hasApiKey()) {
            throw new AiAssistantError('This feature is not available in the free version.', '1688157373215')
        }

        // queue to not overload the server in seo-image-alt-text-generator
        let resolve: (value: string | PromiseLike<string>) => void, reject: (reason?: any) => void;
        const promise = new Promise<string>((localResolve, localReject) => {
            resolve = localResolve;
            reject = localReject;
        });
        this.fetchGenerateQueue.push({isFetching: false, promiseId: this.generateUUID(), module, language, user_input, promise, resolve, reject});
        this.fetchNextGenerate().then(() => {});
        return promise;
    }

    private async fetchNextGenerate() {
        // we want to resolve a maximum of 10 promises at a time
        for (let i = 0; i < this.fetchGenerateQueue.length && i < 10; i++) {
            const {isFetching, promiseId, module, language, user_input, resolve, reject} = this.fetchGenerateQueue[i];
            if (!isFetching) {
                this.fetchGenerateQueue[i].isFetching = true;
                // We need to pass credentials here to omit the Nginx cache
                this.fetchGenerate(module, language, user_input)
                    .then(result => resolve(result))
                    .catch(reject)
                    .finally(() => {
                        this.fetchGenerateQueue = this.fetchGenerateQueue.filter(item => item.promiseId !== promiseId);
                        this.fetchNextGenerate();
                    });
            }
        }
    }

    private generateUUID() {
        let dt = new Date().getTime();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = (dt + Math.random()*16)%16 | 0;
            dt = Math.floor(dt/16);
            return (c === 'x' ? r : (r&0x3|0x8)).toString(16);
        });
    }


    fetchGenerate = async (module: string, language: string, user_input: object = {}) => {
        const response = await fetch(`${this.apiDomain}/api/v1/chat?language=${language}`, {
            method: "POST", // or 'PUT'
            headers: {
                "Content-Type": "application/json",
                "Authorization": `Bearer ${this.apiKey}`,
                "Accept": "application/json"
            },
            body: JSON.stringify({
                module,
                platform: "neos",
                user_input
            })
        });

        const jsonData = await response.json()

        if (response.status === 401) {
            throw new AiAssistantError('The NEOSidekick api key provided is not valid.', '1688158193038')
        } else if (response.status < 200 || response.status >= 400) {
            let message = jsonData?.message;
            if (jsonData?.errors) {
                message += '<br>' + Object.values(jsonData.errors).join('<br>');
            }
            throw new AiAssistantError('An error occurred while asking NEOSidekick', '1688158257149', message)
        }

        let message = jsonData?.data?.message?.message
        // Truncate obsolete quotation marks, if is string
        if(typeof message === 'string' && message.startsWith('"') && message.endsWith('"')) {
            message = message.substr(1, message.length-2);
        }
        return message
    }

    getBackendNotification = async (name: string): Promise<string|null> => {
        try {
            const response = await fetch(`${this.apiDomain}/api/v1/backend-notification?name=${name}&language=${this.interfaceLanguage}`, {
                headers: {
                    "Content-Type": "application/json",
                    "Authorization": `Bearer ${this.apiKey}`,
                    "Accept": "application/json"
                }
            });

            const jsonData = await response.json()

            if (response.status < 200 || response.status >= 400) {
                // This function is not vital for the plugin, so we just log it and return an empty string
                console.warn('An error occurred while asking NEOSidekick: could not load backend notification')
                return null
            }

            return jsonData?.message
        } catch (e) {
            // This function is not vital for the plugin, so we just log it and return an empty string
            console.warn('An error occurred while asking NEOSidekick: could not load backend notification')
            return null
        }
    }
}

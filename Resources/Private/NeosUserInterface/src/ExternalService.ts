import AiAssistantError from "./AiAssistantError";
export const createExternalService = (frontendConfiguration): ExternalService => {
    const configuration = frontendConfiguration['NEOSidekick.AiAssistant'];
    return new ExternalService(configuration['apiDomain'], configuration['apiKey'])
}

export class ExternalService {
    private readonly apiDomain: string = ''
    private readonly apiKey: string = ''

    constructor(apiDomain: string, apiKey: string) {
        this.apiDomain = apiDomain
        this.apiKey = apiKey
    }

    hasApiKey = () => {
        return this.apiKey !== null && this.apiKey !== ''
    }

    generate = async (module, language, title, content) => {
        if (!this.apiKey) {
            throw new AiAssistantError('This feature is not available in the free version.', '1688157373215')
        }

        const response = await fetch(`${this.apiDomain}/api/v1/chat?language=${language}`, {
            method: "POST", // or 'PUT'
            headers: {
                "Content-Type": "application/json",
                "Authorization": `Bearer ${this.apiKey}`,
                "Accept": "application/json"
            },
            body: JSON.stringify({
                module: "meta_description",
                platform: "neos",
                user_input: [
                    {"identifier": "title", "value": title},
                    {"identifier": "content", "value": content},
                ]
            })
        });

        if (response.status === 401) {
            throw new AiAssistantError('The AISidekick api key provided is not valid.', '1688158193038')
        } else if (response.status < 200 || response.status >= 400) {
            throw new AiAssistantError('An error occurred while asking AISidekick.', '1688158257149')
        }

        const jsonData = await response.json()
        return jsonData?.data?.message?.message
    }
}

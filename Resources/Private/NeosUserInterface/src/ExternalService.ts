export const createExternalService = (frontendConfiguration): ExternalService => {
    const configuration = frontendConfiguration['NEOSidekick.AiAssistant'];
    return new ExternalService(configuration['apiDomain'], configuration['apiKey'])
}

class ExternalService {
    private readonly apiDomain: string = ''
    private readonly apiKey: string = ''

    constructor(apiDomain: string, apiKey: string) {
        this.apiDomain = apiDomain
        this.apiKey = apiKey
    }

    generate = async (module, language, title, content) => {
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

        const jsonData = await response.json()
        return jsonData?.data?.message?.message
    }
}

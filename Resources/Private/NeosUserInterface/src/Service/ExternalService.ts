import AiAssistantError from "../AiAssistantError";
import {SidekickFrontendConfiguration} from "../interfaces";
export const createExternalService = (configuration: SidekickFrontendConfiguration): ExternalService => {
    return new ExternalService(configuration.apiDomain, configuration.apiKey);
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

    generate = async (module: string, language: string, user_input: object = {}) => {
        const jsonData = await this.fetch(`/api/v1/chat?language=${language}`, {
            method: "POST", // or 'PUT'
            body: JSON.stringify({
                module,
                platform: "neos",
                user_input,
            })
        })

        let message = jsonData?.data?.message?.message;
        // Truncate obsolete quotation marks
        if(message.startsWith('"') && message.endsWith('"')) {
            message = message.substring(1, message.length-1);
        }
        return message;
    }

    fetch = async (path: string, options: object = {}) => {
        if (!this.apiKey) {
            throw new AiAssistantError('This feature is not available in the free version.', '1688157373215')
        }

        const response = await fetch(this.apiDomain + path, Object.assign({}, options, {
            headers: {
                "Content-Type": "application/json",
                "Authorization": `Bearer ${this.apiKey}`,
                "Accept": "application/json",
            },
        }));

        const jsonData = await response.json();

        if (response.status === 401) {
            throw new AiAssistantError('The NEOSidekick api key provided is not valid.', '1688158193038');
        } else if (response.status < 200 || response.status >= 400) {
            throw new AiAssistantError('An error occurred while asking NEOSidekick.', '1688158257149', jsonData?.message);
        }

        return jsonData;
    }
}

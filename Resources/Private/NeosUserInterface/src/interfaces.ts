export interface SidekickFrontendConfiguration {
    enabled: boolean;
    apiDomain: string;
    apiKey: string;
    userId: string;
    siteName: string;
    domain: string;
    referrer: string;
    defaultLanguage: string;
    chatSidebarEnabled: boolean;
    modifyTextModalPreferCustomPrompt: boolean;
    userInterfaceLanguage: string;
}

export interface ServerStreamMessage {
    data : {
        eventName: 'write-content' | 'stopped-generation' | 'error';
        data: {
            modalTarget?: boolean
            nodePath?: string;
            propertyName?: string;
            value?: string;
            isFinished?: boolean;
            message?: string; // error case
        };
    }
}

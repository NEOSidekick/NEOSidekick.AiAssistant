export interface SidekickFrontendConfiguration {
    enabled: boolean;
    apiDomain: string;
    apiKey: string;
    userId: string;
    sessionId: string;
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
        eventName: 'write-content' | 'stopped-generation' | 'error' | 'reload-content-canvas' | 'show-document-node' | 'show-content-node';
        data: {
            modalTarget?: boolean
            nodePath?: string;
            propertyName?: string;
            value?: string;
            isFinished?: boolean;
            message?: string; // error case
            sourceTool?: string;
            toolCallId?: string;
            documentNodeId?: string;
            contentNodeId?: string;
            href?: string;
        };
    }
}

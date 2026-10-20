export interface SidekickFrontendConfiguration {
    enabled: boolean;
    apiDomain: string;
    apiKey: string;
    userId: string;
    sessionsIsSameSite: boolean;
    /**
     * Installed version of this package. Optional: dev checkouts cannot resolve a version and the
     * Eel helper returns an empty string there, so the iframe parameter is omitted.
     */
    pluginVersion?: string;
    siteName: string;
    domain: string;
    referrer: string;
    defaultLanguage: string;
    chatSidebarEnabled: boolean;
    modifyTextModalPreferCustomPrompt: boolean;
    userInterfaceLanguage: string;
}

export interface ServerStreamMessage {
    data: {
        eventName:
            | "write-content"
            | "stopped-generation"
            | "error"
            | "reload-content"
            | "show-document-node"
            | "show-content-node"
            | "get-content-tree"
            | "neosidekick-silent-authorize";
        data: {
            modalTarget?: boolean;
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
    };
}

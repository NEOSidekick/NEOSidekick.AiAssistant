
export interface Workspace {
    name: string;
    title?: string;
    description?: string | null;
    readonly?: boolean;
    publishableNodes?: any[];
    baseWorkspace?: string;
}
export default interface Workspaces {
    [key: string]: Workspace;
}

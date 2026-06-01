// @ts-ignore
import { Store } from 'react-redux';
import { Node } from '@neos-project/neos-ts-interfaces';
import backend from '@neos-project/neos-ui-backend-connector';
import { actions } from '@neos-project/neos-ui-redux-store';

export interface ExtractedNode {
    id: string | null;
    nodeType: string | null;
    properties: Record<string, unknown>;
    children: Record<string, ChildSlot>;
    _notLoaded?: boolean;
}

export interface ChildSlot {
    id?: string;
    nodeType?: string;
    allowedTypes: string[];
    nodes: ExtractedNode[];
}

interface NodeTypesRegistryLike {
    getRole(role: string): string;
    hasRole(nodeType: string, role: string): boolean;
    get(nodeType: string): { constraints?: { nodeTypes?: Record<string, boolean> } } | null;
}

export class ContentTreeService {
    private store: Store;
    private nodeTypesRegistry: NodeTypesRegistryLike;

    constructor(store: Store, nodeTypesRegistry: NodeTypesRegistryLike) {
        this.store = store;
        this.nodeTypesRegistry = nodeTypesRegistry;
    }

    getDocumentContentTree(): { generatedAt: string; rootNode: ExtractedNode } | null {
        const state = this.store.getState();
        const documentContextPath = state?.cr?.nodes?.documentNode;
        if (!documentContextPath) {
            return null;
        }

        const rootNode = this.buildExtractedNode(documentContextPath);
        if (!rootNode) {
            return null;
        }

        return {
            generatedAt: new Date().toISOString(),
            rootNode
        };
    }

    async getFullDocumentContentTree(): Promise<{
        generatedAt: string;
        rootNode: ExtractedNode;
    } | null> {
        await this.ensureAllContentNodesLoaded();
        return this.getDocumentContentTree();
    }

    async ensureAllContentNodesLoaded(): Promise<void> {
        const state = this.store.getState();
        const documentContextPath = state?.cr?.nodes?.documentNode;
        if (!documentContextPath) return;

        const tree = this.getDocumentContentTree();
        if (!tree || !this.hasNotLoadedNodes(tree.rootNode)) return;

        const { q } = backend.get();
        const contentCollectionType = this.nodeTypesRegistry.getRole('contentCollection');
        const contentType = this.nodeTypesRegistry.getRole('content');
        const filter = `[instanceof ${contentCollectionType}],[instanceof ${contentType}]`;

        const self = await q(documentContextPath).get();
        const directChildren = await q(documentContextPath).children(filter).get();
        const allDescendants =
            directChildren.length > 0 ? await q(directChildren).find(filter).get() : [];

        const nodeMap: Record<string, unknown> = {};
        [...(Array.isArray(self) ? self : [self]), ...directChildren, ...allDescendants].forEach(
            (n: { contextPath?: string }) => {
                if (n?.contextPath) nodeMap[n.contextPath] = n;
            }
        );
        this.store.dispatch(actions.CR.Nodes.merge(nodeMap));
    }

    hasNotLoadedNodes(node: ExtractedNode): boolean {
        if (node._notLoaded) return true;
        for (const slot of Object.values(node.children)) {
            for (const n of slot.nodes) {
                if (this.hasNotLoadedNodes(n)) return true;
            }
        }
        return false;
    }

    private buildExtractedNode(contextPath: string): ExtractedNode | null {
        const state = this.store.getState();
        const allNodes = state.cr.nodes.byContextPath || {};
        const transientValues = state.ui?.inspector?.valuesByNodePath || {};
        const node = allNodes[contextPath] as Node | undefined;

        if (!node) {
            return {
                id: null,
                nodeType: null,
                properties: {},
                children: {},
                _notLoaded: true
            };
        }

        const properties = this.extractProperties(node, transientValues[contextPath]);
        const children = this.extractChildren(node, allNodes, transientValues);

        return {
            id: node.identifier,
            nodeType: node.nodeType,
            properties,
            children
        };
    }

    private extractProperties(
        node: Node,
        transientValues?: Record<string, { value?: unknown }>
    ): Record<string, unknown> {
        const raw: Record<string, unknown> = { ...node.properties };
        if (transientValues && typeof transientValues === 'object') {
            for (const [k, v] of Object.entries(transientValues)) {
                if (v && typeof v === 'object' && 'value' in v) {
                    raw[k] = v.value;
                }
            }
        }

        const result: Record<string, unknown> = {};
        for (const [key, value] of Object.entries(raw)) {
            if (!this.shouldIncludeProperty(key)) continue;
            result[key] = this.serializePropertyValue(value);
        }
        return result;
    }

    private shouldIncludeProperty(name: string): boolean {
        if (name.startsWith('_')) return name === '_hidden';
        return true;
    }

    private serializePropertyValue(value: unknown): unknown {
        if (value && typeof value === 'object' && '__identity' in value) {
            return { identifier: (value as { __identity: string }).__identity };
        }
        if (Array.isArray(value)) {
            return value.map((item) => this.serializePropertyValue(item));
        }
        return value;
    }

    private extractChildren(
        node: Node,
        allNodes: Record<string, unknown>,
        transientValues: Record<string, Record<string, { value?: unknown }>>
    ): Record<string, ChildSlot> {
        const children: Record<string, ChildSlot> = {};
        const childStubs = (node.children || []).filter(
            (c: { role?: string }) =>
                this.nodeTypesRegistry.hasRole((c as { nodeType: string }).nodeType, 'content') ||
                this.nodeTypesRegistry.hasRole((c as { nodeType: string }).nodeType, 'contentCollection')
        );

        const isContentCollection = this.nodeTypesRegistry.hasRole(node.nodeType, 'contentCollection');

        if (isContentCollection) {
            const allowedTypes = this.extractAllowedTypes(node.nodeType);
            const selfNodes: ExtractedNode[] = [];
            for (const stub of childStubs) {
                const stubObj = stub as { contextPath: string; nodeType: string };
                const childNode = allNodes[stubObj.contextPath] as Node | undefined;
                const isNamedSlot = childNode && this.isNamedChildNode(childNode, node.nodeType);
                if (!isNamedSlot) {
                    const extracted = this.buildExtractedNode(stubObj.contextPath);
                    if (extracted) selfNodes.push(extracted);
                }
            }
            children['_self'] = { allowedTypes, nodes: selfNodes };
        }

        for (const stub of childStubs) {
            const stubObj = stub as { contextPath: string; nodeType: string };
            const childNode = allNodes[stubObj.contextPath] as Node | undefined;
            if (!childNode) {
                const extracted = this.buildExtractedNode(stubObj.contextPath);
                if (extracted?._notLoaded) {
                    const slotName = this.getNodeNameFromContextPath(stubObj.contextPath);
                    children[slotName] = {
                        id: null,
                        nodeType: stubObj.nodeType,
                        allowedTypes: [],
                        nodes: [extracted]
                    };
                }
                continue;
            }
            if (!this.nodeTypesRegistry.hasRole(childNode.nodeType, 'contentCollection')) continue;
            const slotName = childNode.name;
            if (!slotName || (isContentCollection && slotName in children)) continue;

            const allowedTypes = this.extractAllowedTypes(childNode.nodeType);
            const slotChildStubs = (childNode.children || []).filter(
                (c: { role?: string }) =>
                    this.nodeTypesRegistry.hasRole((c as { nodeType: string }).nodeType, 'content') ||
                    this.nodeTypesRegistry.hasRole((c as { nodeType: string }).nodeType, 'contentCollection')
            );
            const slotNodes = slotChildStubs.map((s: { contextPath: string }) =>
                this.buildExtractedNode(s.contextPath)
            ).filter(Boolean) as ExtractedNode[];

            children[slotName] = {
                id: childNode.identifier,
                nodeType: childNode.nodeType,
                allowedTypes,
                nodes: slotNodes
            };
        }

        return children;
    }

    private isNamedChildNode(childNode: Node, parentNodeType: string): boolean {
        const parentType = this.nodeTypesRegistry.get(parentNodeType);
        const childNodesConfig = (parentType as { childNodes?: Record<string, unknown> })?.childNodes;
        if (!childNodesConfig || typeof childNodesConfig !== 'object') return false;
        return childNode.name in childNodesConfig;
    }

    private getNodeNameFromContextPath(contextPath: string): string {
        const pathPart = contextPath.split('@')[0] || '';
        const segments = pathPart.split('/').filter(Boolean);
        return segments.length > 0 ? segments[segments.length - 1] : 'unknown';
    }

    private extractAllowedTypes(nodeTypeName: string): string[] {
        const nodeType = this.nodeTypesRegistry.get(nodeTypeName);
        const constraints = nodeType?.constraints?.nodeTypes;
        if (!constraints || typeof constraints !== 'object') return [];
        const allowed: string[] = [];
        for (const [name, isAllowed] of Object.entries(constraints)) {
            if (name === '*' || isAllowed !== true) continue;
            allowed.push(name);
        }
        return allowed;
    }
}

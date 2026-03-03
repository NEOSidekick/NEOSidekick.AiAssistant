// @ts-ignore
import { Store } from 'react-redux';
import { Node } from '@neos-project/neos-ts-interfaces';

export interface ContentTreeNode {
    contextPath: string;
    identifier?: string;
    nodeType?: string;
    name?: string;
    label?: string;
    isFullyLoaded?: boolean;
    isAutoCreated?: boolean;
    depth?: number;
    properties?: Record<string, any>;
    children: ContentTreeNode[];
    _notLoaded?: boolean;
}

export class ContentTreeService {
    private store: Store;

    constructor(store: Store) {
        this.store = store;
    }

    getDocumentContentTree(): ContentTreeNode | null {
        const state = this.store.getState();
        const documentContextPath = state?.cr?.nodes?.documentNode;
        if (!documentContextPath) {
            return null;
        }

        const allNodes = state.cr.nodes.byContextPath || {};
        const transientValues = state.ui?.inspector?.valuesByNodePath || {};

        const buildSubtree = (contextPath: string): ContentTreeNode => {
            const node = allNodes[contextPath] as Node | undefined;
            if (!node) {
                return {
                    contextPath,
                    children: [],
                    _notLoaded: true
                };
            }

            // Overlay transient (unapplied) inspector edits
            const properties: Record<string, any> = { ...node.properties };
            const nodeTransients = transientValues[contextPath];
            if (nodeTransients && typeof nodeTransients === 'object') {
                for (const [propName, transient] of Object.entries(nodeTransients)) {
                    if (transient && typeof transient === 'object' && 'value' in transient) {
                        properties[propName] = (transient as { value: any }).value;
                    }
                }
            }

            const contentChildren = (node.children || [])
                .filter((c: { role?: string }) => c.role === 'content')
                .map((c: { contextPath: string }) => buildSubtree(c.contextPath));

            return {
                contextPath: node.contextPath,
                identifier: node.identifier,
                nodeType: node.nodeType,
                name: node.name,
                label: node.label,
                isFullyLoaded: node.isFullyLoaded,
                isAutoCreated: node.isAutoCreated,
                depth: node.depth,
                properties,
                children: contentChildren
            };
        };

        return buildSubtree(documentContextPath);
    }

    getDocumentContentTreeAsJsx(): string {
        const tree = this.getDocumentContentTree();
        if (!tree) {
            return '';
        }
        return this.treeToJsx(tree);
    }

    treeToJsx(node: ContentTreeNode, indent = 0): string {
        const pad = '  '.repeat(indent);

        if (node._notLoaded) {
            const contextPath = node.contextPath || 'unknown';
            return `${pad}<NotLoaded contextPath="${contextPath}" />`;
        }

        const typeName = node.nodeType || 'Unknown';

        // Merge all properties (regular + internal) with node-level metadata
        const allProps: Record<string, any> = { ...node.properties };

        // Add node-level internal attrs: _nodeName, _nodeIdentifier, _contextPath, _isAutoCreated, _depth
        if (node.name !== undefined) allProps._nodeName = node.name;
        if (node.identifier !== undefined) allProps._nodeIdentifier = node.identifier;
        if (node.contextPath !== undefined) allProps._contextPath = node.contextPath;
        if (node.isAutoCreated !== undefined) allProps._isAutoCreated = node.isAutoCreated;
        if (node.depth !== undefined) allProps._depth = node.depth;

        const props = Object.entries(allProps)
            .map(([k, v]) => {
                const val =
                    typeof v === 'string'
                        ? `"${String(v).replace(/"/g, '\\"')}"`
                        : `{${JSON.stringify(v)}}`;
                return `${k}=${val}`;
            })
            .join(' ');

        const childrenJsx = node.children
            .map((child) => this.treeToJsx(child, indent + 1))
            .join('\n');

        const tagContent = props ? ` ${props}` : '';

        if (childrenJsx) {
            return `${pad}<${typeName}${tagContent}>\n${childrenJsx}\n${pad}</${typeName}>`;
        }
        return `${pad}<${typeName}${tagContent} />`;
    }
}

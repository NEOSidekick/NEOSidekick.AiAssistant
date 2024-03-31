import {GlobalRegistry, Node, NodeType} from '@neos-project/neos-ts-interfaces';
// @ts-ignore
import {Store} from 'react-redux'
import backend from '@neos-project/neos-ui-backend-connector';
import AiAssistantError from '../AiAssistantError'
import {actions, selectors} from '@neos-project/neos-ui-redux-store';
import {produce} from 'immer';
// @ts-ignore
import mapValues from 'lodash.mapvalues';
import {ApiService} from "./ApiService";

export const createContentService = (globalRegistry: GlobalRegistry, store: Store): ContentService => {
    return new ContentService(globalRegistry, store);
}

export class ContentService {
    private globalRegistry: GlobalRegistry;
    private store: Store;
    private guestFrameContentDocument: Document | null = null;
    public nodeTypesRegistry;
    public externalService: ApiService;

    constructor(globalRegistry: GlobalRegistry, store: Store) {
        this.globalRegistry = globalRegistry;
        this.store = store;
        this.nodeTypesRegistry = globalRegistry.get('@neos-project/neos-ui-contentrepository');
        // @ts-ignore
        this.externalService = globalRegistry.get('NEOSidekick.AiAssistant').get('externalService');
    }

    getGuestFrameContentDocument = (): Document | null => {
        const guestFrame = document.getElementsByName('neos-content-main')[0] as HTMLIFrameElement;
        this.guestFrameContentDocument = guestFrame?.contentDocument;
        return this.guestFrameContentDocument;
    }

    getGuestFrameDocumentTitle = (): string => {
        return <string>this.getGuestFrameContentDocument()?.title;
    }

    getGuestFrameDocumentHtml = (): string => {
        return <string>this.getGuestFrameContentDocument()?.body?.innerHTML;
    }

    getCurrentDocumentNode = (): Node => {
        const state = this.store.getState();
        return selectors.CR.Nodes.documentNodeSelector(state);
    }

    getCurrentDocumentParentNode = (): Node => {
        const state = this.store.getState();
        const node = this.getCurrentDocumentNode();
        return selectors.CR.Nodes.nodeByContextPath(state)(node.parent);
    }

    getCurrentDocumentNodeType = (): NodeType => {
        const currentDocumentNode = this.getCurrentDocumentNode();
        return this.globalRegistry.get('@neos-project/neos-ui-contentrepository').get(currentDocumentNode?.nodeType);
    }

    getCurrentDocumentTargetAudience = async (): Promise<string> => {
        // @ts-ignore
        const targetAudience = this.getCurrentDocumentNodeType()?.options?.sidekick?.targetAudience
        if (targetAudience) {
            return this.processClientEval(targetAudience);
        }
        return '';
    }

    getCurrentDocumentPageBriefing = async (): Promise<string> => {
        // @ts-ignore
        const pageBriefing = this.getCurrentDocumentNodeType()?.options?.sidekick?.pageBriefing
        if (pageBriefing) {
            return this.processClientEval(pageBriefing);
        }
        return '';
    }

    getCurrentDocumentFocusKeyword = async (): Promise<string> => {
        // @ts-ignore
        const focusKeyword = this.getCurrentDocumentNodeType()?.options?.sidekick?.focusKeyword
        if (focusKeyword) {
            return await this.processClientEval(focusKeyword)
        }
        return '';
    }

    processClientEval = async (value: any, node?: Node, parentNode?: Node): Promise<string> => {
        if (typeof value === 'string' && (value.startsWith('SidekickClientEval:') || value.startsWith('ClientEval:'))) {
            try {
                node = node ?? this.getCurrentDocumentNode()

                const transientValues = selectors.UI.Inspector.transientValues(this.store.getState())
                node = this.generateNodeForContext(node, transientValues)

                parentNode = parentNode ?? this.getCurrentDocumentParentNode()
                const documentTitle = this.getGuestFrameDocumentTitle()
                const documentContent = this.getGuestFrameDocumentHtml()
                // Functions
                const AssetUri = this.getImageMetadata
                const AsyncFunction = Object.getPrototypeOf(async function () {
                }).constructor
                const evaluateFn = new AsyncFunction('node,parentNode,documentTitle,documentContent,AssetUri', 'return ' + value.replace('SidekickClientEval:', '').replace('ClientEval:', ''));
                return await evaluateFn(node, parentNode, documentTitle, documentContent, AssetUri)
            } catch (e) {
                if (e instanceof AiAssistantError) {
                    throw e
                } else {
                    console.error(e)
                    throw new AiAssistantError('An error occurred while trying to evaluate "' + value + '"', '1694682118365', value)
                }
            }
        }
        return value;
    }

    processValueWithClientEval = async (value: any, node?: Node, parentNode?: Node): Promise<any> => {
        if (typeof value === 'string') {
            return this.processClientEval(value, node, parentNode)
        }

        if (typeof value === 'object' && value !== null) {
            return this.processObjectWithClientEval(value, node, parentNode)
        }

        if (value === null) {
            return null;
        }

        if (Array.isArray(value)) {
            return Promise.all(value.map(async itemValue => {
                return this.processObjectWithClientEval(itemValue, node, parentNode)
            }))
        }
    }

    processObjectWithClientEval = async (obj: object, node?: Node, parentNode?: Node): Promise<object> => {
        const result = {};
        await Promise.all(Object.keys(obj).map(async key => {
            // @ts-ignore
            const value: any = obj[key];
            // @ts-ignore
            result[key] = await this.processValueWithClientEval(value, node, parentNode)
        }))
        return result;
    }

    private getImageMetadata = async (propertyValue: any): Promise<string[]> => {
        if (!propertyValue || !propertyValue?.__identity) {
            throw new AiAssistantError('The property does not have a valid image.', '1694595562191')
        }

        // Fetch image object
        // @ts-ignore
        const {loadImageMetadata} = backend.get().endpoints;
        let imageUri, previewUri
        try {
            const image = await loadImageMetadata(propertyValue?.__identity)
            imageUri = image?.originalImageResourceUri
            previewUri = image?.previewImageResourceUri
        } catch (e) {
            throw new AiAssistantError('Could not fetch image object.', '1694595598880')
        }

        if (!imageUri || imageUri === '') {
            throw new AiAssistantError('The given image does not have a correct url.', '1694595462402')
        }

        let imagesArray = []
        imagesArray.push(this.prependConfiguredDomainToImageUri(imageUri))
        if (previewUri) {
            imagesArray.push(this.prependConfiguredDomainToImageUri(previewUri))
        }
        return imagesArray
    }

    private prependConfiguredDomainToImageUri(imageUri: string) {
        // Make sure that the imageUri has a domain prepended
        // Get instance domain from configuration
        const instanceDomain = this.globalRegistry.get('NEOSidekick.AiAssistant').get('configuration').domain
        // Remove the scheme and split URL into parts
        const imageUriParts = imageUri.replace('http://', '').replace('https://', '').split('/')
        // Remove the domain
        imageUriParts.shift()
        // Add the domain from configuration
        imageUriParts.unshift(instanceDomain)
        // Re-join the array to a URL and return
        return imageUriParts.join('/')
    }

    public getCurrentlyFocusedNodePathAndProperty () {
        const state = this.store.getState()
        const nodeTypesRegistry = this.globalRegistry.get('@neos-project/neos-ui-contentrepository')
        const nodePath = state?.cr?.nodes?.focused?.contextPaths[0]
        // @ts-ignore
        const node = nodePath ? selectors.CR.Nodes.nodeByContextPath(state)(nodePath) : null
        return {
            nodePath,
            node,
            property: state?.ui?.contentCanvas?.currentlyEditedPropertyName,
            nodeType: node ? nodeTypesRegistry.get(node?.nodeType) : null,
            // @ts-ignore
            parentNode: node ? selectors.CR.Nodes.nodesByContextPathSelector(state)[node.parent] : null
        }
    }

    public async evaluateNodeTypeConfigurationAndStartGeneration(node: Node, propertyName: string, nodeType: NodeType, parentNode?: Node, isOnCreate: boolean = false)
    {
        if (!nodeType) {
            return;
        }

        if (propertyName[0] === '_') {
            return;
        }

        const propertyConfiguration = nodeType.properties ? nodeType.properties[propertyName] : null;

        try {
            // Warn about legacy configuration
            if (propertyConfiguration?.options?.sidekick?.onCreate?.module) {
                throw new AiAssistantError('Please do not use options.sidekick.onCreate.module anymore. Read the docs to find out about the new configuration.', '1696264259260')
            }

            if (!propertyConfiguration?.options?.sidekick?.module) {
                return;
            }

            if (isOnCreate && propertyConfiguration?.options?.sidekick?.onCreate !== true) {
                return;
            }

            if (!propertyConfiguration?.ui?.inlineEditable) {
                throw new AiAssistantError('You can only generate content on inline editable properties', '1688395273728')
            }

            if (!this.externalService.hasApiKey()) {
                throw new AiAssistantError('This feature is not available in the free version.', '1688157373215')
            }
        } catch (e) {
            const i18nRegistry = this.globalRegistry.get('i18n')
            this.store.dispatch(actions.UI.FlashMessages.add(e?.code ?? e?.message, e?.code ? i18nRegistry.translate('NEOSidekick.AiAssistant:Error:' + e.code) : e?.message, e?.severity ?? 'error'))
            return;
        }

        const configuration = JSON.parse(JSON.stringify(propertyConfiguration.options.sidekick))
        const contentCanvasService = this.globalRegistry.get('NEOSidekick.AiAssistant').get('contentCanvasService')
        const processedData = await this.processObjectWithClientEval(configuration, node, parentNode);
        contentCanvasService.streamGenerationIntoInlineProperty(node.contextPath, propertyName, processedData);
    }

    private generateNodeForContext(node: Node, transientValues: any) {
        if (transientValues) {
            return produce(node, draft => {
                // @ts-ignore
                const mappedTransientValues = mapValues(transientValues, item => item?.value);
                draft.properties = Object.assign({}, draft.properties, mappedTransientValues);
            });
        }

        return node;
    }
}

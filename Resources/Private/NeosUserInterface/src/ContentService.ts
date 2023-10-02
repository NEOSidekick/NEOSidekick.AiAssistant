import {SynchronousMetaRegistry} from "@neos-project/neos-ui-extensibility";
import {Node, NodeType} from '@neos-project/neos-ts-interfaces';
import {Store} from 'react-redux'
import backend from '@neos-project/neos-ui-backend-connector';
import AiAssistantError from './AiAssistantError'
import {actions, selectors} from '@neos-project/neos-ui-redux-store';
import {produce} from 'immer';
import mapValues from 'lodash.mapvalues';

export const createContentService = (globalRegistry: SynchronousMetaRegistry<any>, store: Store): ContentService => {
    return new ContentService(globalRegistry, store)
}

export class ContentService {
    private globalRegistry: SynchronousMetaRegistry<any>;
    private store: Store;
    public nodeTypesRegistry;
    public externalService;


    constructor(globalRegistry: SynchronousMetaRegistry<any>, store: Store) {
        this.globalRegistry = globalRegistry
        this.store = store
        this.nodeTypesRegistry = globalRegistry.get('@neos-project/neos-ui-contentrepository')
        this.externalService = globalRegistry.get('NEOSidekick.AiAssistant').get('externalService')
    }

    getGuestFrameDocumentTitle = (): string => {
        const guestFrame = document.getElementsByName('neos-content-main')[0];
        // @ts-ignore
        const guestFrameDocument = guestFrame?.contentDocument;
        return guestFrameDocument?.title
    }

    getGuestFrameDocumentHtml = (): string => {
        const guestFrame = document.getElementsByName('neos-content-main')[0];
        // @ts-ignore
        const guestFrameDocument = guestFrame?.contentDocument;
        return guestFrameDocument?.body?.innerHTML;
    }

    getCurrentDocumentNode = (): Node => {
        const state = this.store.getState()
        const currentDocumentNodePath = state?.cr?.nodes?.documentNode
        return state?.cr?.nodes?.byContextPath[currentDocumentNodePath]
    }

    getCurrentDocumentParentNode = (): Node => {
        const state = this.store.getState()
        const currentDocumentNode = this.getCurrentDocumentNode()
        return state?.cr?.nodes?.byContextPath[currentDocumentNode.parent]
    }

    getCurrentDocumentNodeType = (): NodeType => {
        const currentDocumentNode = this.getCurrentDocumentNode()
        return this.globalRegistry.get('@neos-project/neos-ui-contentrepository').get(currentDocumentNode?.nodeType)
    }

    getCurrentDocumentTargetAudience = async (): string => {
        const targetAudience = this.getCurrentDocumentNodeType()?.options?.sidekick?.targetAudience
        if (targetAudience) {
            return await this.processClientEval(targetAudience)
        }
        return null
    }

    getCurrentDocumentPageBriefing = async (): string => {
        const pageBriefing = this.getCurrentDocumentNodeType()?.options?.sidekick?.pageBriefing
        if (pageBriefing) {
            return await this.processClientEval(pageBriefing)
        }
        return null
    }

    getCurrentDocumentFocusKeyword = async (): string => {
        const focusKeyword = this.getCurrentDocumentNodeType()?.options?.sidekick?.focusKeyword
        if (focusKeyword) {
            return await this.processClientEval(focusKeyword)
        }

        return null
    }

    processClientEval = async (value: string, node: Node = null, parentNode: Node = null): string => {
        if (typeof value === 'string' && (value.startsWith('SidekickClientEval:') || value.startsWith('ClientEval:'))) {
            try {
                node = node ?? this.getCurrentDocumentNode()

                const transientValues = selectors.UI.Inspector.transientValues(this.store.getState())
                node = this.generateNodeForContext(node, transientValues)

                parentNode = parentNode ?? this.getCurrentDocumentParentNode()
                const documentTitle = this.getGuestFrameDocumentTitle()
                const documentContent = this.getGuestFrameDocumentHtml()
                // Functions
                const AssetUri = await this.getImageMetadata
                const AsyncFunction = Object.getPrototypeOf(async function () {
                }).constructor
                const evaluateFn = new AsyncFunction('node,parentNode,documentTitle,documentContent,AssetUri', 'return ' + value.replace('SidekickClientEval:', '').replace('ClientEval:', ''));
                return await evaluateFn(node, parentNode, documentTitle, documentContent, AssetUri)
            } catch (e) {
                if (e instanceof AiAssistantError) {
                    throw e
                } else {
                    console.error(e)
                    throw new AiAssistantError('An error occurred while trying to evaluate "' + value + '"', 1694682118365, value)
                }
            }
        }
        return value;
    }

    processObjectWithClientEval = async (obj: object, node: Node = null, parentNode: Node = null): object => {
        await Promise.all(Object.keys(obj).map(async key => {
            const value: any = obj[key]
            if (typeof value === 'string') {
                obj[key] = await this.processClientEval(value, node, parentNode)
            }

            if (typeof value === 'object' && value !== null) {
                obj[key] = await this.processObjectWithClientEval(value, node, parentNode)
            }

            if (Array.isArray(value)) {
                obj[key] = await Promise.all(value.map(async itemValue => {
                    return await this.processObjectWithClientEval(itemValue, node, parentNode)
                }))
            }
        }))
        return obj
    }

    private getImageMetadata = async (propertyValue: any): string => {
        if (!propertyValue || !propertyValue?.__identity) {
            throw new AiAssistantError('The property does not have a valid image.', 1694595562191)
        }

        // Fetch image object
        const {loadImageMetadata} = backend.get().endpoints;
        let imageUri, previewUri
        try {
            const image = await loadImageMetadata(propertyValue?.__identity)
            imageUri = image?.originalImageResourceUri
            previewUri = image?.previewImageResourceUri

        } catch (e) {
            throw new AiAssistantError('Could not fetch image object.', 1694595598880)
        }

        if (!imageUri || imageUri === '') {
            throw new AiAssistantError('The given image does not have a correct url.', 1694595462402)
        }

        let imagesArray = []
        imagesArray.push(this.prependConfiguredDomainToImageUri(imageUri))
        if (previewUri) {
            imagesArray.push(this.prependConfiguredDomainToImageUri(previewUri))
        }
        return imagesArray
    }

    private prependConfiguredDomainToImageUri(imageUri) {
        // Make sure that the imageUri has a domain prepended
        // Get instance domain from configuration
        const instanceDomain = this.globalRegistry.get('NEOSidekick.AiAssistant').get('configuration').domain
        // Remove the scheme and split URL into parts
        imageUri = imageUri.replace('http://', '').replace('https://').split('/')
        // Remove the domain
        imageUri.shift()
        // Add the domain from configuration
        imageUri.unshift(instanceDomain)
        // Re-join the array to an URL and return
        return imageUri.join('/')
    }

    public getCurrentlyFocusedNodePathAndProperty () {
        const state = this.store.getState()
        const nodeTypesRegistry = this.globalRegistry.get('@neos-project/neos-ui-contentrepository')
        const nodePath = state?.cr?.nodes?.focused?.contextPaths[0]
        const node = nodePath ? selectors.CR.Nodes.nodeByContextPath(state)(nodePath) : null
        return {
            nodePath,
            node,
            property: state?.ui?.contentCanvas?.currentlyEditedPropertyName,
            nodeType: node ? nodeTypesRegistry.get(node?.nodeType) : null,
            parentNode: node ? selectors.CR.Nodes.nodesByContextPathSelector(state)[node.parent] : null
        }
    }

    public evaluateNodeTypeConfigurationAndStartGeneration(node: Node, propertyName: string, nodeType: NodeType, parentNode: Node = null)
    {
        if (!nodeType) {
            return;
        }

        if (propertyName[0] === '_') {
            return;
        }

        const propertyConfiguration = nodeType.properties[propertyName]
        if (!propertyConfiguration?.options?.sidekick?.onCreate) {
            return;
        }

        try {
            if (!propertyConfiguration?.ui?.inlineEditable) {
                throw new AiAssistantError('You can only generate content on inline editable properties', '1688395273728')
            }

            if (!this.externalService.hasApiKey()) {
                throw new AiAssistantError('This feature is not available in the free version.', '1688157373215')
            }
        } catch (e) {
            this.store.dispatch(actions.UI.FlashMessages.add(e?.code ?? e?.message, e?.code ? i18nRegistry.translate('NEOSidekick.AiAssistant:Error:' + e.code) : e?.message, e?.severity ?? 'error'))
        }

        const configuration = JSON.parse(JSON.stringify(propertyConfiguration.options.sidekick.onCreate))
        const assistantService = this.globalRegistry.get('NEOSidekick.AiAssistant').get('assistantService')
        this.processObjectWithClientEval(configuration, node, parentNode)
            .then(processedData => {
                const message = {
                    version: '1.0',
                    eventName: 'call-module',
                    data: {
                        'platform': 'neos',
                        'target': {
                            'nodePath': node.contextPath,
                            'propertyName': propertyName
                        },
                        ...processedData
                    }
                }
                assistantService.sendMessageToIframe(message)
            })
    }

    private generateNodeForContext(node, transientValues) {
        if (transientValues) {
            return produce(node, draft => {
                const mappedTransientValues = mapValues(transientValues, item => item?.value);
                draft.properties = Object.assign({}, draft.properties, mappedTransientValues);
            });
        }

        return node;
    }
}

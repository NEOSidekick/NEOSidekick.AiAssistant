import AiAssistantError from "../AiAssistantError";
import {Node} from "@neos-project/neos-ts-interfaces";
import {selectors} from "@neos-project/neos-ui-redux-store";
import {DocumentNodeListItem} from "../Model/ListItem";
import {ListItemProperty} from "../Model/ListItemProperty";

export class ContentService {
    private static instance: ContentService | null = null;
    private apiDomain: string = ''
    private apiKey: string = ''
    private interfaceLanguage: string = 'en';
    private currentProcessingHtmlContent: string = '';

    public static getInstance(): ContentService
    {
        if (!ContentService.instance) {
            ContentService.instance = new ContentService();
        }
        return ContentService.instance
    }


    processObjectWithClientEvalFromDocumentNodeListItem = async (obj: object, item: DocumentNodeListItem, htmlContent: string): Promise<object> => {
        this.currentProcessingHtmlContent = htmlContent;
        return this.processObjectWithClientEval(obj, this.createFakePartialNode(item), this.createFakeParentNode());
    }

    processClientEvalFromDocumentNodeListItem = async (value: any, item: DocumentNodeListItem, property: ListItemProperty, htmlContent: string): Promise<any> => {
        this.currentProcessingHtmlContent = htmlContent;
        return this.processClientEval(value, this.createFakePartialNode(item), this.createFakeParentNode(), property);
    }



    /*
     *
     * Mimik Neos UI and DistributionPackages/NEOSidekick.AiAssistant/Resources/Private/NeosUserInterface/src/Service/ContentService.ts
     *
     */

    processClientEval = async (value: any, node?: Node, parentNode?: Node, property?: ListItemProperty): Promise<string> => {
        if (typeof value === 'string' && (value.startsWith('SidekickClientEval:') || value.startsWith('ClientEval:'))) {
            try {
                if (!node || !parentNode) {
                    throw new Error('ContentService.processClientEval() always requires a node and a parentNode')
                }
                let documentTitle = null;
                let documentContent = null;
                if (value.indexOf('documentTitle') !== -1 || value.indexOf('documentContent') !== -1) {
                    if (!this.currentProcessingHtmlContent) {
                        throw new Error('ContentService.processClientEval() currently does not support documentTitle and documentContent, because the htmlContent is not set.');
                    }
                    // eval only when needed
                    documentTitle = this.getGuestFrameDocumentTitle();
                    documentContent = this.getGuestFrameDocumentHtml();
                }

                // Functions
                const AssetUri = () => {
                    throw new Error('ContentService.processClientEval() does not support AssetUri(...)');
                }
                const AssetTitle = (assetObjectArray: any, fallbackValue?: string) => this.getAssetProperty('title', assetObjectArray, fallbackValue || '')
                const AssetCaption = (assetObjectArray: any, fallbackValue?: string) => this.getAssetProperty('caption', assetObjectArray, fallbackValue || '')
                const AsyncFunction = Object.getPrototypeOf(async function () {
                }).constructor
                const evaluateFn = new AsyncFunction('node,parentNode,documentTitle,documentContent,AssetUri,AssetTitle,AssetCaption,property', 'return ' + value.replace('SidekickClientEval:', '').replace('ClientEval:', ''));
                return await evaluateFn(node, parentNode, documentTitle, documentContent, AssetUri, AssetTitle, AssetCaption, property)
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

    private getAssetProperty = async (propertyName: 'title' | 'caption', assetObjectArray: { __identity?: string }, fallbackValue: string = ''): Promise<string> => {
        if (!assetObjectArray || !assetObjectArray?.__identity) {
            return fallbackValue;
        }
        try {
            const imagePropertiesAsJson = await fetch('/neosidekick/aiassistant/service/imageTitleAndCaption?image=' + assetObjectArray?.__identity, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                }
            });
            const imageProperties: {title: string, caption: string} = await imagePropertiesAsJson.json();
            return imageProperties[propertyName] || fallbackValue;
        } catch (e) {
            return fallbackValue;
        }
    }

    private createFakePartialNode(item: DocumentNodeListItem) {
        const dimensionProxy = new Proxy({
            language: item.language
        }, {
            get: function (target, prop) {
                if (!target.hasOwnProperty(prop)) {
                    throw new Error('This is a fake node dimension, and only exposes the language dimension');
                }
                return target[prop];
            }
        });
        return new Proxy({
            contextPath: item.nodeContextPath,
            nodeType: item.nodeTypeName,
            properties: item.properties,
            dimensions: dimensionProxy,
        }, {
            get: function (target, prop) {
                if (!target.hasOwnProperty(prop)) {
                    // @ts-ignore
                    throw new Error('This is a fake node, and does not expose the property ' + prop);
                }
                return target[prop];
            }
        });
    }

    private createFakeParentNode() {
        return new Proxy({}, {
            get: function (target, prop) {
                // @ts-ignore
                throw new Error('This is a fake node, and does not expose the property ' + prop);
            }
        });
    }

    getGuestFrameDocumentTitle() {
        if (!this.currentProcessingHtmlContent) {
            return null;
        }

        let parser = new DOMParser();
        let doc = parser.parseFromString(this.currentProcessingHtmlContent, "text/html");
        return doc.title;
    }

    getGuestFrameDocumentHtml() {
        return this.currentProcessingHtmlContent;
    }

    /*
     *
     * EVERYTHING BELOW IS A COPY OF THE DistributionPackages/NEOSidekick.AiAssistant/Resources/Private/NeosUserInterface/src/Service/ContentService.ts
     *
     *
     */


    processValueWithClientEval = async (value: any, node?: Node, parentNode?: Node): Promise<any> => {
        if (typeof value === 'string') {
            return this.processClientEval(value, node, parentNode)
        }

        if (typeof value === 'object' && value !== null) {
            return this.processObjectWithClientEval(value, node, parentNode)
        }

        if (typeof value === 'boolean') {
            return value;
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
}



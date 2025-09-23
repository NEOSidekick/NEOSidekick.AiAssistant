import React from "react";
import PureComponent from "../../Components/PureComponent";
import {EditorOptions, ListItemProperty, ListItemPropertyState, PropertySchema} from "../../Model/ListItemProperty";
import TextAreaEditor from "../../Components/Editor/TextAreaEditor";
import {DocumentNodeListItem, ListItemState} from "../../Model/ListItem";
import AppContext, {AppContextType} from "../../AppContext";
import Alert from "../../Components/Alert";
import FocusKeywordEditor from "../../Components/Editor/FocusKeywordEditor";
import {Draft, produce} from "immer";

interface DocumentNodeListItemPropertyProps {
    item: DocumentNodeListItem;
    property: ListItemProperty;
    readonly?: boolean
    htmlContent: string;
    updateItemProperty(value: string, state: ListItemPropertyState): void;
}
export default class DocumentNodeListViewItemProperty extends PureComponent<DocumentNodeListItemPropertyProps, {}> {
    static contextType = AppContext;
    context: AppContextType;

    private canChangeValue(): boolean
    {
        const {item, property, readonly} = this.props;
        return !readonly && item.state === ListItemState.Initial && property.state !== ListItemPropertyState.Generating;
    }

    render() {
        const {item, property, readonly} = this.props;
        const propertySchema = this.context.nodeTypes[item.nodeTypeName]?.properties?.[property.propertyName] as PropertySchema;

        if (!propertySchema) {
            return; // ignore properties that no not exist on this node type
        }

        let finalPropertySchema = propertySchema;
        switch (propertySchema?.ui?.inspector?.editor) {
            case 'NEOSidekick.AiAssistant/Inspector/Editors/FocusKeywordEditor':
                if (readonly) {
                    return (
                        <TextAreaEditor
                            disabled={true}
                            property={property}
                            propertySchema={propertySchema}
                            item={item}
                            rows={1}
                            updateItemProperty={() => {}}
                        />
                    );
                }
                return (
                    <FocusKeywordEditor
                        disabled={!this.canChangeValue()}
                        property={property}
                        propertySchema={propertySchema}
                        item={item}
                        htmlContent={this.props.htmlContent}
                        updateItemProperty={(value: string, state: ListItemPropertyState) => this.props.updateItemProperty(value, state)}
                    />
                )
            case 'Neos.Neos/Inspector/Editors/TextFieldEditor':
            case 'Neos.Neos/Inspector/Editors/TextAreaEditor':
                return (
                    <TextAreaEditor
                        disabled={!this.canChangeValue()}
                        property={property}
                        propertySchema={propertySchema}
                        item={item}
                        htmlContent={this.props.htmlContent}
                        updateItemProperty={(value: string, state: ListItemPropertyState) => this.props.updateItemProperty(value, state)}
                    />
                )
            case 'NEOSidekick.AiAssistant/Inspector/Editors/ImageAltTextEditor':
            case 'NEOSidekick.AiAssistant/Inspector/Editors/ImageTitleEditor':
                if (!propertySchema?.ui?.inspector?.editorOptions?.imagePropertyName) {
                    return <div style={{background: '#ff460d', color: '#fff', padding: '8px'}}>Incorrect YAML Configuration: Image Text Editor requires an editorOption <i>imagePropertyName</i></div>;
                }

                let module = propertySchema.ui.inspector.editor === 'NEOSidekick.AiAssistant/Inspector/Editors/ImageAltTextEditor' ? 'image_alt_text' : 'image_title';
                finalPropertySchema = createMagicTextAreaEditorPropsForImageTextEditor(propertySchema, module);
                // fall through
            case 'NEOSidekick.AiAssistant/Inspector/Editors/MagicTextFieldEditor':
            case 'NEOSidekick.AiAssistant/Inspector/Editors/MagicTextAreaEditor':
                if (propertySchema?.ui?.inspector?.editorOptions?.module === 'meta_description') {
                    // For the meta description we always want to prefer quality to speed in the bulk module
                    finalPropertySchema = JSON.parse(JSON.stringify(propertySchema));
                    finalPropertySchema.ui.inspector.editorOptions.arguments.prefer = 'quality';
                }
                return (
                    <TextAreaEditor
                        disabled={!this.canChangeValue()}
                        property={property}
                        propertySchema={finalPropertySchema}
                        item={item}
                        htmlContent={this.props.htmlContent}
                        updateItemProperty={(value: string, state: ListItemPropertyState) => this.props.updateItemProperty(value, state)}
                        autoGenerateIfActionsMatch={true}
                        showGenerateButton={!readonly}
                        showResetButton={!readonly}
                    />
                )
            default:
                if (propertySchema?.ui?.inspector?.editor) {
                    return <Alert message={`[${item.nodeTypeName}:${property.propertyName}] Editor "${propertySchema.ui.inspector.editor}" is currently not supported`}/>
                } else {
                    return <Alert message={`[${item.nodeTypeName}:${property.propertyName}] Editor configuration is missing`}/>
                }
        }
    }
}


// Keep in sync with Resources/Private/NeosUserInterface/src/Editors/ImageAltTextEditor.tsx
export function createMagicTextAreaEditorPropsForImageTextEditor(propertySchema: any, module: string, supportsPlaceholder: boolean = true): any {
    Object.keys(propertySchema?.ui?.inspector?.editorOptions).forEach(key => {
        if (!['imagePropertyName', 'fallbackAssetPropertyName', 'fallbackToCleanedFilenameIfNothingIsSet', 'autoGenerateIfImageChanged'].includes(key)) {
            console.warn('[NEOSidekick.AiAssistant]: Image text editor does not support editorOption "' + key + '".');
        }
    });

    return produce(propertySchema, (draft: Draft<any>) => {
        let options = draft.ui.inspector.editorOptions as EditorOptions;
        let imagePropertyName = options.imagePropertyName;
        let fallbackAssetPropertyName = options.fallbackAssetPropertyName;
        let fallbackToCleanedFilenameIfNothingIsSet = options.fallbackToCleanedFilenameIfNothingIsSet !== false;

        options = options || {};
        draft.ui.inspector.editorOptions.module = options.module || module;

        if (!imagePropertyName) {
            console.warn('[NEOSidekick.AiAssistant]: Could not find inspector editors registry.');
            console.warn('[NEOSidekick.AiAssistant]: Skipping registration of InspectorEditor...');
            throw new Error('imagePropertyName is required');
        }

        if (fallbackAssetPropertyName) {
            options.arguments = options.arguments || {};
            options.arguments.url = options.arguments.url || `SidekickClientEval: AssetUri(node.properties.${imagePropertyName})`;
            let filenameFallback = fallbackToCleanedFilenameIfNothingIsSet ? 'true' : 'false';
            switch (supportsPlaceholder && fallbackAssetPropertyName) {
                case 'title':
                    options.placeholder = options.placeholder || `SidekickClientEval: AssetTitle(node.properties.${imagePropertyName}, ${filenameFallback})`;
                    break;
                case 'caption':
                    options.placeholder = options.placeholder || `SidekickClientEval: AssetCaption(node.properties.${imagePropertyName}, ${filenameFallback})`;
                    break;
            }
        }
    });
}

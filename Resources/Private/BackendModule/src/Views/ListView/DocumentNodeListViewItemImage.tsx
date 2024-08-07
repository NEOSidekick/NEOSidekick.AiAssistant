import {ListItemPropertyState, PropertySchema} from "../../Model/ListItemProperty";
import AppContext, {AppContextType} from "../../AppContext";
import PureComponent from "../../Components/PureComponent";
import {DocumentNodeListItem, ListItemState} from "../../Model/ListItem";
import React from "react";
import TextAreaEditor, {TextAreaEditorSidekickConfiguration} from "../../Components/Editor/TextAreaEditor";
import {ListItemImage} from "../../Model/ListItemImage";
import {produce} from "immer";
import {node} from "prop-types";

interface DocumentNodeListViewItemImageProps {
    item: DocumentNodeListItem;
    imageProperty: ListItemImage;
    htmlContent: string;
    updateItemProperty(propertyName: string, value: string, state: ListItemPropertyState): void;
}

export default class DocumentNodeListViewItemImage extends PureComponent<DocumentNodeListViewItemImageProps, {}> {
    static contextType = AppContext;
    context: AppContextType;

    private canChangeAlternativeTextValue(): boolean
    {
        const {item, imageProperty} = this.props;
        return item.state === ListItemState.Initial && imageProperty.alternativeTextProperty?.state !== ListItemPropertyState.Generating;
    }

    private canChangeTitleTextValue(): boolean
    {
        const {item, imageProperty} = this.props;
        return item.state === ListItemState.Initial && imageProperty.titleProperty?.state !== ListItemPropertyState.Generating;
    }

    private getLabel(): string
    {
        const {item, imageProperty} = this.props;
        const nodeTypeSchema = this.context.nodeTypes[item.nodeTypeName];
        const imagePropertySchema = this.context.nodeTypes[imageProperty.nodeTypeName]?.properties?.[imageProperty.imagePropertyName] as PropertySchema;
        const nodeTypeLabelTranslation = this.translationService.translate(nodeTypeSchema.label, nodeTypeSchema.label);
        const imagePropertyLabelTranslation = this.translationService.translate(imagePropertySchema.ui?.label, imagePropertySchema.ui?.label);
        return `${nodeTypeLabelTranslation} / ${imagePropertyLabelTranslation}`;
    }

    render() {
        const {item, imageProperty} = this.props;
        const alternativeTextPropertySchema = imageProperty.alternativeTextProperty?.propertyName ? this.context.nodeTypes[imageProperty.nodeTypeName]?.properties?.[imageProperty.alternativeTextProperty.propertyName] as PropertySchema : null;
        const titlePropertySchema = imageProperty.titleProperty?.propertyName ? this.context.nodeTypes[item.nodeTypeName]?.properties?.[imageProperty.titleProperty.propertyName] as PropertySchema : null;

        if (!alternativeTextPropertySchema && !titlePropertySchema) {
            return; // ignore properties that do not exist on this node type
        }

        // todo this truncates all other sidekick configuration options, refactor!
        let alternativeTextSidekickConfiguration: TextAreaEditorSidekickConfiguration | null = null;
        if (alternativeTextPropertySchema) {
            alternativeTextSidekickConfiguration = produce(alternativeTextPropertySchema.ui.inspector.editorOptions, (draft: any) => ({
                module: draft.module,
                userInput: [
                    {
                        'identifier': 'url',
                        'value': [imageProperty.fullsizeUri, imageProperty.thumbnailUri]
                    },
                    {
                        'identifier': 'filename',
                        'value': imageProperty.filename
                    },
                    {
                        'identifier': 'content',
                        'value': this.props.htmlContent
                    }
                ]
            }));
        }

        // todo sidekick configuration for title properties
        let titleTextSidekickConfiguration: TextAreaEditorSidekickConfiguration | null = null;
        if (titlePropertySchema) {
            titleTextSidekickConfiguration = produce(titlePropertySchema.ui.inspector.editorOptions, (draft: any) => ({
                module: draft.module,
                userInput: [
                    {
                        'identifier': 'url',
                        'value': [imageProperty.fullsizeUri, imageProperty.thumbnailUri]
                    },
                    {
                        'identifier': 'filename',
                        'value': imageProperty.filename
                    },
                    {
                        'identifier': 'content',
                        'value': this.props.htmlContent
                    }
                ]
            }));
        }

        return (
            <div style={{marginBottom: '32px'}}>
                <label><strong>{this.getLabel()}</strong></label>
                <div style={{backgroundColor: '#3f3f3f', marginBottom: '16px', display: 'flex'}}>
                    <img src={imageProperty.thumbnailUri} alt="" style={{maxHeight: '300px', maxWidth: '100%', margin: 'auto'}}/>
                </div>
                {alternativeTextSidekickConfiguration ? <TextAreaEditor
                    disabled={!this.canChangeAlternativeTextValue()}
                    property={imageProperty.alternativeTextProperty}
                    propertySchema={alternativeTextPropertySchema}
                    item={item}
                    htmlContent={this.props.htmlContent}
                    sidekickConfiguration={alternativeTextSidekickConfiguration}
                    autoGenerateIfActionsMatch={true}
                    updateItemProperty={(value: string, state: ListItemPropertyState) => this.props.updateItemProperty('alternativeTextProperty', value, state)}/> : null}
                {titleTextSidekickConfiguration ? <TextAreaEditor
                    disabled={!this.canChangeTitleTextValue()}
                    property={imageProperty.titleProperty}
                    propertySchema={titlePropertySchema}
                    item={item}
                    htmlContent={this.props.htmlContent}
                    sidekickConfiguration={titleTextSidekickConfiguration}
                    autoGenerateIfActionsMatch={true}
                    updateItemProperty={(value: string, state: ListItemPropertyState) => this.props.updateItemProperty('titleProperty', value, state)} /> : null}
            </div>
        )
    }
}

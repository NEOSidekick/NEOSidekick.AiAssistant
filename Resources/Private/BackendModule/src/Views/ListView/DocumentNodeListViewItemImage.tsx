import {ListItemPropertyState, PropertySchema} from "../../Model/ListItemProperty";
import AppContext, {AppContextType} from "../../AppContext";
import PureComponent from "../../Components/PureComponent";
import {DocumentNodeListItem} from "../../Model/ListItem";
import React from "react";
import TextAreaEditor from "../../Components/Editor/TextAreaEditor";
import {ListItemImage} from "../../Model/ListItemImage";

interface DocumentNodeListViewItemImageProps {
    item: DocumentNodeListItem;
    imageProperty: ListItemImage;
    htmlContent: string;
    updateItemProperty(propertyName: string, value: string, state: ListItemPropertyState): void;
}

export default class DocumentNodeListViewItemImage extends PureComponent<DocumentNodeListViewItemImageProps, {}> {
    static contextType = AppContext;
    context: AppContextType;

    render() {
        const {item, imageProperty} = this.props;
        const alternativeTextPropertySchema = this.context.nodeTypes[item.nodeTypeName]?.properties?.[imageProperty.alternativeTextProperty.propertyName] as PropertySchema;
        const titlePropertySchema = this.context.nodeTypes[item.nodeTypeName]?.properties?.[imageProperty.titleProperty.propertyName] as PropertySchema;

        if (!alternativeTextPropertySchema && !titlePropertySchema) {
            return; // ignore properties that no not exist on this node type
        }

        return (
            <div>
                <label>{imageProperty.label}</label>
                <div style={{aspectRatio: '4/3', backgroundColor: 'black'}}>
                    <img src={imageProperty.thumbnailUri} alt="" style={{width: '100%', height: '100%', 'object-fit': 'contain'}}/>
                </div>
                <TextAreaEditor
                    disabled={false}
                    property={imageProperty.alternativeTextProperty}
                    item={item}
                    updateItemProperty={(value: string, state: ListItemPropertyState) => {
                        this.props.updateItemProperty('alternativeTextProperty', value, state)
                    }} />
            </div>
        )
    }
}

import React, {Component} from 'react';
import MagicTextAreaEditor from "./MagicTextAreaEditor";
import {Draft, produce} from "immer";

import "./index.css";

export interface EditorOptions {
    placeholder?: string
    module?: string
    arguments?: {
        [key: string]: any
    },
    imagePropertyName?: string,
    fallbackAssetPropertyName?: string,
}

// Keep in sync with Resources/Private/BackendModule/src/Views/ListView/DocumentNodeListViewItemProperty.tsx
export function createMagicTextAreaEditorPropsForImageTextEditor(props: any, module: string): any {
    Object.keys(props.options).forEach(key => {
        if (!['imagePropertyName', 'fallbackAssetPropertyName'].includes(key)) {
            console.warn('[NEOSidekick.AiAssistant]: Image text editor does not support editorOption "' + key + '".');
        }
    });

    return produce(props, (draft: Draft<any>) => {
        let options = draft.options as EditorOptions;
        let imagePropertyName = options.imagePropertyName;
        let fallbackAssetPropertyName = options.fallbackAssetPropertyName;

        options = options || {};
        options.module = options.module || module;

        if (!imagePropertyName) {
            console.warn('[NEOSidekick.AiAssistant]: Could not find inspector editors registry.');
            console.warn('[NEOSidekick.AiAssistant]: Skipping registration of InspectorEditor...');
            throw new Error('imagePropertyName is required');
        }

        if (fallbackAssetPropertyName) {
            options.arguments = options.arguments || {};
            options.arguments.url = options.arguments.url || `SidekickClientEval: AssetUri(node.properties.${imagePropertyName})`;
            switch (fallbackAssetPropertyName) {
                case 'title':
                    options.placeholder = options.placeholder || `SidekickClientEval: AssetTitle(node.properties.${imagePropertyName})`;
                    break;
                case 'caption':
                    options.placeholder = options.placeholder || `SidekickClientEval: AssetCaption(node.properties.${imagePropertyName})`;
                    break;
            }
        }
    });
}

export default class ImageAltTextEditor extends Component<any, {}> {
    render() {
        if (!this.props.options?.imagePropertyName) {
            return <div style={{background: '#ff460d', color: '#fff', padding: '8px'}}>Incorrect YAML Configuration: ImageAltTextEditor requires an editorOption <i>imagePropertyName</i></div>;
        }
        return <MagicTextAreaEditor {...createMagicTextAreaEditorPropsForImageTextEditor(this.props, 'image_alt_text')} />
    }
}

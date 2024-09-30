import React, {Component} from 'react';
import MagicTextAreaEditor from "./MagicTextAreaEditor";
import {createMagicTextAreaEditorPropsForImageTextEditor} from "./ImageAltTextEditor";

import "./index.css";

export default class ImageTitleEditor extends Component<any, {}> {
    render() {
        if (!this.props.options?.imagePropertyName) {
            return <div style={{background: '#ff460d', color: '#fff', padding: '8px'}}>Incorrect YAML Configuration: ImageTitleEditor requires an editorOption <i>imagePropertyName</i></div>;
        }
        return <MagicTextAreaEditor {...createMagicTextAreaEditorPropsForImageTextEditor(this.props, 'image_title')} />
    }
}

import React, {Component} from 'react';
import MagicTextAreaEditor from "./MagicTextAreaEditor";
import {createMagicTextAreaEditorPropsForImageTextEditor} from "./ImageAltTextEditor";
import {neos} from "@neos-project/neos-ui-decorators";

import "./index.css";

@neos(globalRegistry => ({
    i18nRegistry: globalRegistry.get('i18n')
}))
export default class ImageTitleEditor extends Component<any, {}> {
    render() {
        if (!this.props.options?.imagePropertyName) {
            return <div style={{background: '#ff460d', color: '#fff', padding: '8px'}}>{this.props.i18nRegistry.translate('NEOSidekick.AiAssistant:Main:error.imageTitleEditorMissingImagePropertyName', 'Incorrect YAML configuration: ImageTitleEditor requires an editorOption imagePropertyName')}</div>;
        }
        return <MagicTextAreaEditor {...createMagicTextAreaEditorPropsForImageTextEditor(this.props, 'image_title')} />
    }
}

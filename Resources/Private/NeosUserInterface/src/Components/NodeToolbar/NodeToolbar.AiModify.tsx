import React, {PureComponent} from 'react';
import PropTypes from 'prop-types';
import {connect} from 'react-redux';

import {selectors} from '@neos-project/neos-ui-redux-store';
import {neos} from '@neos-project/neos-ui-decorators';
import {Button} from "@neos-project/react-ui-components";
import {actions as sidekickActions} from "../../actions";
import {GlobalRegistry} from "@neos-project/neos-ts-interfaces";

@neos((globalRegistry: GlobalRegistry) => ({
    nodeTypesRegistry: globalRegistry.get('@neos-project/neos-ui-contentrepository'),
    contentCanvasService: globalRegistry.get('NEOSidekick.AiAssistant').get('contentCanvasService'),
}))
@connect(state => ({
    focusedNodePath: selectors.CR.Nodes.focusedNodePathSelector(state),
    currentlyEditedPropertyName: selectors.UI.ContentCanvas.currentlyEditedPropertyName(state),
}), {
    openModal: sidekickActions.openModal,
})
export default class AiModify extends PureComponent {
    static propTypes = {
        className: PropTypes.string,
        i18nRegistry: PropTypes.object.isRequired,
        focusedNodePath: PropTypes.string,
        currentlyEditedPropertyName: PropTypes.string,
    };

    handleOpen = () => {
        const {focusedNodePath, currentlyEditedPropertyName} = this.props;
        const fullText = this.props.contentCanvasService.getEditorContent(focusedNodePath, currentlyEditedPropertyName);
        const selectedText = this.props.contentCanvasService.getSelectedContent(focusedNodePath, currentlyEditedPropertyName);
        this.props.openModal(fullText, selectedText);
    }

    render() {
        const {currentlyEditedPropertyName, i18nRegistry} = this.props;

        return (
            <Button
                id="neos-InlineToolbar-AiModify"
                disabled={!currentlyEditedPropertyName}
                className={this.props.className}
                style="transparent"
                hoverStyle="brand"
                onClick={this.handleOpen}
                title={i18nRegistry.translate('NEOSidekick.AiAssistant:Main:title', 'Mit AI bearbeiten')}>
                <strong>AI</strong>
            </Button>
        );
    }
}

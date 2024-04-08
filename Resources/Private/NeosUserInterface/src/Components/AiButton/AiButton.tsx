import React, {PureComponent} from 'react';
import PropTypes from 'prop-types';
import {Button, Icon} from '@neos-project/react-ui-components';
import {neos} from '@neos-project/neos-ui-decorators';
import {connect} from "react-redux";
import {actions as sidekickActions} from "../../actions";
import {selectors} from "@neos-project/neos-ui-redux-store";

import "./AiButton.css";
import {ContentService} from "../../Service/ContentService";
import {ContentCanvasService} from "../../Service/ContentCanvasService";
import {GlobalRegistry, I18nRegistry} from "@neos-project/neos-ts-interfaces";

interface AiButtonProps {
    contentService: ContentService;
    contentCanvasService: ContentCanvasService;
    i18nRegistry: I18nRegistry;
    openModal: Function;
    focusedNodePath: string;
    currentlyEditedPropertyName: string;
}

@neos((globalRegistry: GlobalRegistry) => ({
    contentService: globalRegistry.get('NEOSidekick.AiAssistant').get('contentService'),
    contentCanvasService: globalRegistry.get('NEOSidekick.AiAssistant').get('contentCanvasService'),
    i18nRegistry: globalRegistry.get('i18n'),
}))
@connect(state => ({
    focusedNodePath: selectors.CR.Nodes.focusedNodePathSelector(state),
    currentlyEditedPropertyName: selectors.UI.ContentCanvas.currentlyEditedPropertyName(state),
}), {
    openModal: sidekickActions.openModal,
})
export default class AiButton extends PureComponent<AiButtonProps> {
    static propTypes = {
        contentService: PropTypes.object.isRequired,
        contentCanvasService: PropTypes.object.isRequired,
        i18nRegistry: PropTypes.object.isRequired,
        openModal: PropTypes.func.isRequired,
        focusedNodePath: PropTypes.string,
        currentlyEditedPropertyName: PropTypes.string,
    };

    handleOpen = () => {
        const {focusedNodePath, currentlyEditedPropertyName} = this.props;
        const fullText = this.props.contentCanvasService.getEditorContent(focusedNodePath, currentlyEditedPropertyName);
        const selectedText = this.props.contentCanvasService.getSelectedContent(focusedNodePath, currentlyEditedPropertyName);
        this.props.openModal(fullText, selectedText);
    };

    render() {
        const {i18nRegistry} = this.props;
        return (
            <div>
                <Button
                    className="button"
                    style="transparent"
                    hoverStyle="brand"
                    onClick={this.handleOpen}
                    title={i18nRegistry.translate('NEOSidekick.AiAssistant:Main:title', 'Mit AI bearbeiten')}>
                    AI
                </Button>
            </div>
        );
    }
}

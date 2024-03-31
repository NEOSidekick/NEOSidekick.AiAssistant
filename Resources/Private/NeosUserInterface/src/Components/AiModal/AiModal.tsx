import React, {PureComponent} from 'react';
import PropTypes from "prop-types";
import {connect} from "react-redux";
import {neos} from '@neos-project/neos-ui-decorators';
import {Button, Dialog, TextArea, Icon, Label, SelectBox} from '@neos-project/react-ui-components';
import I18n from '@neos-project/neos-ui-i18n';
import {actions, selectors} from "@neos-project/neos-ui-redux-store";
import {I18nRegistry, NodeTypesRegistry} from "@neos-project/neos-ts-interfaces";
import {ApiService} from "../../Service/ApiService";
import {ContentService} from "../../Service/ContentService";
import {IFrameApiService} from "../../Service/IFrameApiService";
import {ServerStreamMessage, SidekickFrontendConfiguration} from "../../interfaces";
import { actions as sidekickActions, selectors as sidekickSelectors } from '../../actions';
import {ContentCanvasService} from "../../Service/ContentCanvasService";
import CKEditor from "../CKEditor/CKEditor";

import "./AiModal.css";

interface AiModalProps {
    iFrameApiService: IFrameApiService;
    contentService: ContentService;
    contentCanvasService: ContentCanvasService;
    externalService: ApiService;
    i18nRegistry: I18nRegistry;
    nodeTypesRegistry: NodeTypesRegistry;
    configuration: SidekickFrontendConfiguration;
    isOpen: boolean;
    focusedNodePath: string;
    currentlyEditedPropertyName: string;
    fullText: string;
    selectedText: string;
    activeContentDimensions: any;
    node: Node;
    cancelModal: () => void;
    applyModal: () => void;
    addFlashMessage: (code: string, message: string, severity: "success" | "info" | "error", externalMessage?: string) => void;
}

interface AiModalState {
    customPrompt: string;
    writingStyles: Array<{value: string, label: string}>;
    generatedText: string;
    generationState: 'empty' | 'loading' | 'finished';
}

@neos(globalRegistry => ({
    iFrameApiService: globalRegistry.get('NEOSidekick.AiAssistant').get('iFrameApiService'),
    contentService: globalRegistry.get('NEOSidekick.AiAssistant').get('contentService'),
    contentCanvasService: globalRegistry.get('NEOSidekick.AiAssistant').get('contentCanvasService'),
    externalService: globalRegistry.get('NEOSidekick.AiAssistant').get('externalService'),
    i18nRegistry: globalRegistry.get('i18n'),
    nodeTypesRegistry: globalRegistry.get('@neos-project/neos-ui-contentrepository'),
    configuration: globalRegistry.get('NEOSidekick.AiAssistant').get('configuration'),
}))
@connect(state => ({
    isOpen: sidekickSelectors.isModalOpen(state),
    focusedNodePath: selectors.CR.Nodes.focusedNodePathSelector(state),
    currentlyEditedPropertyName: selectors.UI.ContentCanvas.currentlyEditedPropertyName(state),
    fullText: sidekickSelectors.fullText(state),
    selectedText: sidekickSelectors.selectedText(state),
    activeContentDimensions: selectors.CR.ContentDimensions.active(state),
    node: selectors.CR.Nodes.focusedSelector(state),
}), {
    cancelModal: sidekickActions.cancelModal,
    applyModal: sidekickActions.applyModal,
    addFlashMessage: actions.UI.FlashMessages.add,
})
export default class AiModal extends PureComponent<AiModalProps, AiModalState> {
    static propTypes = {
        iFrameApiService: PropTypes.object.isRequired,
        contentService: PropTypes.object.isRequired,
        contentCanvasService: PropTypes.object.isRequired,
        externalService: PropTypes.object.isRequired,
        i18nRegistry: PropTypes.object.isRequired,
        nodeTypesRegistry: PropTypes.object.isRequired,
        configuration: PropTypes.object.isRequired,
        isOpen: PropTypes.bool,
        focusedNodePath: PropTypes.string,
        currentlyEditedPropertyName: PropTypes.string,
        fullText: PropTypes.string,
        selectedText: PropTypes.string,
        activeContentDimensions: PropTypes.object,
        node: PropTypes.object,
        cancelModal: PropTypes.func.isRequired,
        applyModal: PropTypes.func.isRequired,
        addFlashMessage: PropTypes.func.isRequired,
    };

    constructor(props: AiModalProps) {
        super(props);
        this.state = {
            customPrompt: '',
            writingStyles: [{value: '', label: 'Loading...'}],
            generatedText: '',
            generationState: 'empty',
        };
    }

    getLanguage() {
        const {activeContentDimensions, configuration} = this.props as AiModalProps;
        return activeContentDimensions.language ? activeContentDimensions.language[0] : configuration.defaultLanguage;
    }

    getPropertyConfiguration() : any {
        const {node, nodeTypesRegistry} = this.props as AiModalProps;
        // @ts-ignore
        const nodeType = node ? nodeTypesRegistry.get(node?.nodeType) : null;
        const {property} = this.props.contentService.getCurrentlyFocusedNodePathAndProperty();
        if (!node || !nodeType || !property) {
            return null;
        }
        return nodeType?.properties ? nodeType?.properties[property] : null;
    }

    getConfiguredSidekick() {
        return this.getPropertyConfiguration()?.options?.sidekick;
    }

    componentDidMount() {
        const language = this.getLanguage();
        this.props.externalService.fetch('/api/v1/writing-styles?language=' + language).then((data) => {
            let filteredData = data.data.filter((style: any) => style.identifier !== 'default' && style.allowed_languages.includes(language));
            this.setState({writingStyles: filteredData.map((style: any) => ({value: style.identifier, label: style.label}))});
        });

        this.props.iFrameApiService.listenToMessages((message: ServerStreamMessage) => {
            if (!message.data?.data?.modalTarget) {
                return; // ignore messages that are not for the modal
            }

            switch (message?.data?.eventName) {
                case 'write-content':
                    let {value, isFinished} = message.data?.data;

                    this.setState({generatedText: value || ''});

                    if (isFinished) {
                        this.setState({generationState: 'finished'});
                        this.props.iFrameApiService.setStreamingFinished();
                    }
                    break;
                case 'stopped-generation':
                    this.setState({generationState: 'finished'});
                    break;
                case 'error':
                    let errorMessage = message?.data?.data?.message;
                    this.addFlashMessage('1688158257149', 'An error occurred while asking NEOSidekick: ' + errorMessage, 'error', errorMessage);
                    this.setState({generationState: 'finished'});
                    this.props.iFrameApiService.setStreamingFinished();
                    break;
                default:
                    const errorMessage2 = 'Unknown message event: ' + message?.data?.eventName;
                    this.addFlashMessage('1688158257149', 'An error occurred while asking NEOSidekick: ' + errorMessage2, 'error', errorMessage2);
                    this.setState({generationState: 'finished'});
                    this.props.iFrameApiService.setStreamingFinished();
            }
        });

        window.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target === document.getElementById('neosidekickAiModifyPromptTextarea')) {
                this.handleGenerate('custom');
            }
        });
    }

    componentDidUpdate(prevProps: any) {
        // cancel generation on close
        if (prevProps.isOpen && !this.props.isOpen && this.state.generationState === 'loading') {
            this.props.iFrameApiService.cancelCallModule();
        }

        // reset on open
        if (!prevProps.isOpen && this.props.isOpen) {
            this.setState({
                customPrompt: '',
                generatedText: '',
                generationState: 'empty',
            });
        }
    }

    private addFlashMessage(code: string, message: string, severity: "success" |"info" | "error" = 'error', externalMessage?: string): void {
        this.props.addFlashMessage(code, this.props.i18nRegistry.translate('NEOSidekick.AiAssistant:Error:' + code, message, {0: externalMessage}), severity);
    }

    renderTitle() {
        return (
            <div>
                <Icon icon="magic" className="icon--space-right-large"/>
                {this.props.selectedText ? <I18n id="NEOSidekick.AiAssistant:AiModal:modify-text-title" fallback="Revise text"/> : <I18n id="NEOSidekick.AiAssistant:AiModal:create-text-title" fallback="Create text"/>} (Beta)
            </div>
        )
    }

    renderCancelAction() {
        return (
            <Button
                key="cancel"
                style="lighter"
                hoverStyle="brand"
                onClick={this.handleCancel}>
                <I18n id="Neos.Neos:Main:cancel" fallback="Cancel"/>
            </Button>
        );
    }

    renderSaveAction() {
        if (this.state.generationState === 'finished') {
            return (
                <Button
                    key="save"
                    style="success"
                    hoverStyle="success"
                    onClick={this.handleApply}>
                    {this.props.selectedText ? <I18n id="NEOSidekick.AiAssistant:AiModal:apply-modify-text" fallback="Use text"/> : <I18n id="NEOSidekick.AiAssistant:AiModal:apply-create-text" fallback="Insert text"/>}
                </Button>
            );
        }
        return '';
    }

    handleCancel = () => {
        this.props.cancelModal();
        this.setState({generatedText: '', generationState: 'empty'});
    }

    handleApply = () => {
        this.props.applyModal();
        const {focusedNodePath, currentlyEditedPropertyName} = this.props;
        this.props.contentCanvasService.insertTextIntoInlineEditor(focusedNodePath, currentlyEditedPropertyName, this.state.generatedText);
        this.setState({generatedText: '', generationState: 'empty'});
    }

    hasSelectedText() {
        return this.props.selectedText && this.props.selectedText.trim().length > 0;
    }

    renderConfiguredButton() {
        if (this.getConfiguredSidekick()?.module) {
            return (
                <Button
                    style="lighter"
                    hoverStyle="brand"
                    onClick={() => this.handleGenerate('configured')}>
                    <Icon icon="hand-sparkles" className="icon--space-right"/>
                    {this.getConfiguredSidekick()?.label || <I18n id="NEOSidekick.AiAssistant:AiModal:configured-button" fallback="Configured instruction"/>}
                </Button>
            )
        }
        return '';
    }

    renderCreateButtons() {
        return (
            <div className="quickGenerationButtons">
                {this.renderConfiguredButton()}
                <Button
                    style="lighter"
                    hoverStyle="brand"
                    onClick={() => this.handleGenerate('intro')}>
                    <Icon icon="paragraph" className="icon--space-right"/>
                    <I18n id="NEOSidekick.AiAssistant:AiModal:intro-button" fallback="Introduction"/>
                </Button>
                <Button
                    style="lighter"
                    hoverStyle="brand"
                    onClick={() => this.handleGenerate('page_conclusion')}>
                    <Icon icon="paragraph" className="icon--space-right"/>
                    <I18n id="NEOSidekick.AiAssistant:AiModal:page-conclusion-button" fallback="Page Conclusion"/>
                </Button>
            </div>
        )
    }

    renderEditButtons() {
        const {i18nRegistry} = this.props;
        return (
            <div className="quickGenerationButtons">
                <Button
                    style="lighter"
                    hoverStyle="brand"
                    onClick={() => this.handleGenerate('rephrase')}>
                    <Icon icon="redo" className="icon--space-right"/>
                    <I18n id="NEOSidekick.AiAssistant:AiModal:rephrase-button" fallback="Rephrase"/>
                </Button>
                <Button
                    style="lighter"
                    hoverStyle="brand"
                    onClick={() => this.handleGenerate('longer')}>
                    <Icon icon="expand-alt" className="icon--space-right"/>
                    <I18n id="NEOSidekick.AiAssistant:AiModal:longer-button" fallback="Make it longer"/>
                </Button>
                <Button
                    style="lighter"
                    hoverStyle="brand"
                    onClick={() => this.handleGenerate('shorter')}>
                    <Icon icon="compress-alt" className="icon--space-right"/>
                    <I18n id="NEOSidekick.AiAssistant:AiModal:shorter-button" fallback="Make it shorter"/>
                </Button>
                <Button
                    style="lighter"
                    hoverStyle="brand"
                    onClick={() => this.handleGenerate('fix_spelling')}>
                    <Icon icon="spell-check" className="icon--space-right"/>
                    <I18n id="NEOSidekick.AiAssistant:AiModal:fix-text-button" fallback="Fix spelling & grammar"/>
                </Button>
                <div className="quickGenerationButtons__selectWrapper">
                    <SelectBox
                        className="quickGenerationButtons__select"
                        placeholder={i18nRegistry.translate('NEOSidekick.AiAssistant:AiModal:change-tone')}
                        onValueChange={(option: any) => this.handleGenerate('style', option)}
                        options={this.state.writingStyles}/>
                </div>
            </div>
        )
    }

    handleGenerate = async (modificationType: string, writingStyle = 'default'): Promise<void> => {
        if (this.state.generationState === 'loading') {
            this.props.iFrameApiService.cancelCallModule();
        }

        const {customPrompt} = this.state;
        const {fullText, selectedText} = this.props;
        const formattingOptionsConfig = this.getPropertyConfiguration().ui?.inline?.editorOptions?.formatting;
        const allowedMarkdownFormattingOptions = Object.keys(formattingOptionsConfig).filter(i => formattingOptionsConfig[i]).map(option => {
            return {
                'a': 'link',
                'b': 'bold',
                'i': 'italic',
                'ol': 'ordered list',
                'ul': 'unordered list',
            }[option] || option;
        }).join(', ');

        let module: string;
        let args: object;

        switch (modificationType) {
            case 'configured':
                const config = this.getConfiguredSidekick();
                if (config.onCreate?.module) {
                    this.addFlashMessage('1696264259260', 'Please do not use options.sidekick.onCreate.module anymore. Read the docs to find out about the new configuration.', 'error');
                    return;
                }
                const {node, parentNode} = this.props.contentService.getCurrentlyFocusedNodePathAndProperty();
                const processedConfig = await this.props.contentService.processObjectWithClientEval(config, node, parentNode);
                module = processedConfig.module;
                args = processedConfig.arguments || {};
                break;
            case 'intro':
                module = 'free_conversation';
                args = {
                    content: 'Write a two sentence introduction for the current page.',
                    allowedMarkdownFormattingOptions
                };
                break;
            case 'page_conclusion':
                module = 'page_conclusion_writer';
                args = {allowedMarkdownFormattingOptions};
                break;
            case 'custom':
                if (!customPrompt || customPrompt.length < 5 ) {
                    this.addFlashMessage('1696264259270', 'Please write a few words about how to change the text.', 'error');
                    return;
                }
                if (selectedText && selectedText.length) {
                    module = 'modify_selected_text';
                    args = {fullText, selectedText, modificationType, allowedMarkdownFormattingOptions, customPrompt, writingStyle};
                } else {
                    module = 'free_conversation';
                    args = {
                        content: 'Please create additional content for the current page. ' + customPrompt,
                        allowedMarkdownFormattingOptions
                    };
                }
                break;
            default:
                module = 'modify_selected_text';
                args = {fullText, selectedText, modificationType, allowedMarkdownFormattingOptions, customPrompt, writingStyle};
                break;
        }

        this.setState({generatedText: '', generationState: 'loading'});
        const data = {
            'target': {
                'modalTarget': true,
            },
            module,
            arguments: args,
        }
        this.props.iFrameApiService.callModule(data);
    }

    render() {
        const {selectedText, i18nRegistry} = this.props;
        const {customPrompt, generatedText, generationState} = this.state;
        const editorOptions = JSON.parse(JSON.stringify(this.getPropertyConfiguration()?.ui?.inline?.editorOptions) || '{}');
        editorOptions.sidekickModifyButton = false;
        return (
            <Dialog
                actions={[this.renderCancelAction(), this.renderSaveAction()]}
                title={this.renderTitle()}
                onRequestClose={this.handleCancel}
                isOpen={this.props.isOpen}
                style="wide"
            >
                <div className="primaryColumn">
                    {this.hasSelectedText() ? <div className="originalText">
                        <Label><I18n id="NEOSidekick.AiAssistant:AiModal:selected-text" fallback="Selected Text"/>:</Label>
                        <CKEditor
                            value={selectedText}
                            options={editorOptions}
                            disabled={true}
                        />
                    </div> : ''}
                    <div className="promptRow">
                        <TextArea
                            autoFocus
                            id="neosidekickAiModifyPromptTextarea"
                            placeholder={this.hasSelectedText() ? i18nRegistry.translate('NEOSidekick.AiAssistant:AiModal:custom-prompt-modify-placeholder', 'How should the text be changed?') : i18nRegistry.translate('NEOSidekick.AiAssistant:AiModal:custom-prompt-create-placeholder', 'Which text should I write?')}
                            onChange={(value: string) => this.setState({customPrompt: value})}
                            minRows={2}
                            expandedRows={2}
                        />
                        <Button
                            className="promptRow__button"
                            disabled={!customPrompt}
                            style={customPrompt && generationState == 'empty'? 'success' : 'lighter'}
                            hoverStyle="success"
                            onClick={() => this.handleGenerate('custom')}>
                            <Icon icon="magic" className="icon--space-right"/>
                            <I18n id="NEOSidekick.AiAssistant:Main:generate" fallback="Generate"/>
                        </Button>
                    </div>
                    {this.hasSelectedText() ? this.renderEditButtons() : this.renderCreateButtons()}
                    {generationState === 'empty' ? '' : <div className="result">
                        <Label><I18n id="NEOSidekick.AiAssistant:AiModal:new-text" fallback="New Text"/>:</Label>
                        <CKEditor
                            onChange={(value) => this.setState({generatedText: value})}
                            value={generatedText}
                            options={editorOptions}
                        />
                        <i className="result__notice"><I18n id="NEOSidekick.AiAssistant:AiModal:disclaimer" fallback="GenAI models can make mistakes. Check important information."/></i>
                    </div>}
                </div>
            </Dialog>
        );
    }
}

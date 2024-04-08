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
    customPromptIsVisible: boolean;
    customPrompt: string;
    writingStyles: Array<{value: string, label: string}>;
    generatedText: string;
    generationState: 'empty' | 'loading' | 'finished';
    generationModificationType?: string;
    generationWritingStyle?: string;
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
            customPromptIsVisible: false,
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
                customPromptIsVisible: false,
                customPrompt: '',
                generatedText: '',
                generationState: 'empty',
                generationModificationType: undefined,
                generationWritingStyle: undefined,
            });
        }
    }

    private addFlashMessage(code: string, message: string, severity: "success" |"info" | "error" = 'error', externalMessage?: string): void {
        this.props.addFlashMessage(code, this.props.i18nRegistry.translate('NEOSidekick.AiAssistant:Error:' + code, message, {0: externalMessage}), severity);
    }

    renderTitle() {
        return (
            <div>
                <svg className="sidekick-icon" enable-background="new 0 0 245 245" viewBox="0 0 245 245">
                    <path d="m72.6 75.3 5.3-2.6m-18.5-30.7" fill="#fff"/>
                    <circle cx="64.7" cy="50.5" fill="#fff" r="9.4"/>
                    <path d="m68 59.1 7.5 15.2" fill="none" stroke="#fff" stroke-miterlimit="10" stroke-width="4.9606"/>
                    <path d="m170.1 72.7 5.3 2.6m13.2-33.3" fill="#fff"/>
                    <circle cx="183.4" cy="50.5" fill="#fff" r="9.4"/>
                    <path d="m180.1 59.1-7.5 15.2" fill="none" stroke="#fff" stroke-miterlimit="10"
                          stroke-width="4.9606"/>
                    <path
                        d="m123.6 60c38.1 0 69 31 69 69s-31 69-69 69-69-31-69-69 31-69 69-69m0-8.5c-42.8 0-77.5 34.7-77.5 77.5s34.7 77.5 77.5 77.5 77.5-34.7 77.5-77.5-34.6-77.5-77.5-77.5z"
                        fill="#fff"/>
                    <circle cx="97" cy="123.5" fill="#fff" r="9.1"/>
                    <g fill="none" stroke="#fff" stroke-miterlimit="10">
                        <path
                            d="m164.2 137.9h-80c-7.8 0-14.1-6.3-14.1-14.1v-.3c0-7.8 6.3-14.1 14.1-14.1h80c7.8 0 14.1 6.3 14.1 14.1v.3c0 7.8-6.4 14.1-14.1 14.1z"
                            stroke-width="9.0714"/>
                        <path d="m52.4 153.9h-17.5c-4.1 0-7.4-3.2-7.4-7.2v-34.2c0-3.9 3.4-7.2 7.4-7.2h17.5"
                              stroke-width="8.5039"/>
                        <path d="m193.1 153.9h17.5c4.1 0 7.4-3.2 7.4-7.2v-34.2c0-3.9-3.4-7.2-7.4-7.2h-17.5"
                              stroke-width="8.5039"/>
                        <path d="m104.1 165.1s18.1 18.1 39 .5" stroke-linecap="round" stroke-width="8.5039"/>
                    </g>
                </svg>
                {this.props.selectedText ?
                    <I18n id="NEOSidekick.AiAssistant:AiModal:modify-text-title" fallback="Revise text"/> :
                    <I18n id="NEOSidekick.AiAssistant:AiModal:create-text-title" fallback="Generate text with AI"/>
                } (Beta)
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

    renderRegenerateAction() {
        if (this.state.generationState === 'finished') {
            return (
                <Button
                    key="regenerate"
                    style="lighter"
                    hoverStyle="warn"
                    onClick={this.handleRegenerate}>
                    <I18n id="NEOSidekick.AiAssistant:AiModal:regenerate-text" fallback="Regenerate"/>
                </Button>
            );
        }
        return '';
    }

    renderSaveAction() {
        if (this.state.generationState === 'finished') {
            return (
                <Button
                    key="save"
                    style="success"
                    hoverStyle="success"
                    onClick={this.handleApply}>
                    {this.props.selectedText ?
                        <I18n id="NEOSidekick.AiAssistant:AiModal:apply-modify-text" fallback="Use text"/> :
                        <I18n id="NEOSidekick.AiAssistant:AiModal:apply-create-text" fallback="Insert text"/>}
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
        const endsWithSpace = this.props.selectedText.endsWith(' ') || this.props.selectedText.endsWith('&nbsp;');
        this.props.contentCanvasService.insertTextIntoInlineEditor(focusedNodePath, currentlyEditedPropertyName, this.state.generatedText, endsWithSpace);
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
                    <I18n id="NEOSidekick.AiAssistant:AiModal:longer-button-short" fallback="Longer"/>
                </Button>
                <Button
                    style="lighter"
                    hoverStyle="brand"
                    onClick={() => this.handleGenerate('shorter')}>
                    <Icon icon="compress-alt" className="icon--space-right"/>
                    <I18n id="NEOSidekick.AiAssistant:AiModal:shorter-button-short" fallback="Shorter"/>
                </Button>
                <Button
                    style="lighter"
                    hoverStyle="brand"
                    onClick={() => this.handleGenerate('fix_spelling')}>
                    <Icon icon="spell-check" className="icon--space-right"/>
                    <I18n id="NEOSidekick.AiAssistant:AiModal:fix-text-button" fallback="Fix spelling"/>
                </Button>
                <Button
                    style={this.state.customPromptIsVisible ? 'brand' : 'lighter'}
                    hoverStyle="brand"
                    onClick={() => this.setState({customPromptIsVisible: !this.state.customPromptIsVisible})}>
                    <Icon icon="pen-fancy" className="icon--space-right"/>
                    <I18n id="NEOSidekick.AiAssistant:AiModal:custom-prompt-button" fallback="Etwas anderes"/>
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
        this.setState({generationModificationType: modificationType, generationWritingStyle: writingStyle});
        if (modificationType !== 'custom') {
            this.setState({customPromptIsVisible: false});
        }
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

    handleRegenerate = async (): Promise<void> => {
        // @ts-ignore
        return this.handleGenerate(this.state.generationModificationType, this.state.generationWritingStyle || 'default');
    }

    render() {
        const {selectedText, i18nRegistry} = this.props;
        const {customPrompt, generatedText, generationState} = this.state;
        const editorOptions = JSON.parse(JSON.stringify(this.getPropertyConfiguration()?.ui?.inline?.editorOptions) || '{}');
        editorOptions.sidekickModifyButton = false;
        return (
            <Dialog
                actions={[this.renderCancelAction(), this.renderRegenerateAction(), this.renderSaveAction()]}
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
                    {this.hasSelectedText() ? this.renderEditButtons() : ''}
                    {(!this.hasSelectedText() || this.state.customPromptIsVisible) ? (
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
                    ) : ''}
                    {this.hasSelectedText() ? '' : this.renderCreateButtons()}
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

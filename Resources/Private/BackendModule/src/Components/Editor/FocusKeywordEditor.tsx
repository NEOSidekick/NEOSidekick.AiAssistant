import PureComponent from "../PureComponent";
import React from "react";
import {ListItemPropertyState} from "../../Model/ListItemProperty";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faExternalLinkAlt, faSpinner} from "@fortawesome/free-solid-svg-icons";
import TextAreaEditor, {TextAreaEditorProps} from "./TextAreaEditor";
import {SidekickApiService} from "../../Service/SidekickApiService";
import Alert from "../Alert";

interface FocusKeywordEditorProps extends TextAreaEditorProps {
    htmlContent: string
}

export interface FocusKeywordEditorState {
    suggestionsState: 'is-loading' | 'loaded' | 'failed',
    suggestions: string[],
    errorMessage?: string,
}

export default class FocusKeywordEditor extends PureComponent<FocusKeywordEditorProps,FocusKeywordEditorState> {
    constructor(props: FocusKeywordEditorProps) {
        super(props);
        this.state = {
            suggestionsState: 'is-loading',
            suggestions: []
        }
    }

    componentDidUpdate(prevProps: Readonly<FocusKeywordEditorProps>, prevState: Readonly<FocusKeywordEditorState>, snapshot?: any) {
        if (this.props.htmlContent && !prevProps.htmlContent) {
            // noinspection JSIgnoredPromiseFromCall
            this.generateSuggestions();
        }
    }

    private async generateSuggestions(retries = 3) {
        const editorOptions = this.props.propertySchema?.ui.inspector.editorOptions;
        if (editorOptions.module !== 'focus_keyword_generator') {
            new Error('Your NodeType property focusKeyword must have "module: \'focus_keyword_generator\'" configured in the editorOptions');
        }

        const {item, htmlContent} = this.props;
        const sidekickConfiguration = {
            'module': 'focus_keyword_generator',
            'userInput': [
                {
                    identifier: 'content',
                    value: htmlContent
                },
                {
                    identifier: 'url',
                    value: item.publicUri
                }
            ],
        }

        const {module, userInput} = sidekickConfiguration;
        try {
            const generatedValue = await SidekickApiService.getInstance().generate(module, this.props.item.language, userInput);
            if (!Array.isArray(generatedValue) || generatedValue.length === 0) {
                if (retries === 0) {
                    this.setState({
                        suggestionsState: 'failed',
                        errorMessage: this.translationService.translate('NEOSidekick.AiAssistant:Editors.FocusKeywordEditor:suggestionsFailed', 'Could not calculate focus keyword suggestions for the page.')
                    });
                    return;
                }
                return await this.generateSuggestions(retries - 1);
            }
            this.setState({
                suggestionsState: 'loaded',
                suggestions: generatedValue
            });
        } catch (e) {
            this.setState({
                suggestionsState: 'failed',
                errorMessage: this.translationService.fromError(e)
            });
        }
    }

    render () {
        const {disabled, item, property, propertySchema} = this.props;
        const {suggestionsState, suggestions} = this.state;
        const ahrefsLink = `https://app.ahrefs.com/keywords-explorer/google/${item.language.toLowerCase().replace('en', 'uk')}/overview?keyword=${property.currentValue}`
        const googleLink = `https://ads.google.com/aw/keywordplanner/plan/keywords/historical`;


        return (
            <div>
                <TextAreaEditor
                    disabled={disabled}
                    property={property}
                    propertySchema={propertySchema}
                    item={item}
                    updateItemProperty={(value: string, state: ListItemPropertyState) => this.props.updateItemProperty(value, state)}
                    rows={1}
                    marginBottom="5px"/>
                {suggestionsState !== 'failed' && <p style={{padding: '1rem', background: 'gray', marginBottom: '5px'}}>
                    {suggestionsState === 'is-loading' && <span>
                        <FontAwesomeIcon icon={faSpinner} spin={true}/>&nbsp;
                        {this.translationService.translate('NEOSidekick.AiAssistant:Main:loading', 'Loading...')}
                    </span>}
                    {suggestionsState === 'loaded' && <span>
                        <p style={{marginBottom: '7px'}}>{this.translationService.translate('NEOSidekick.AiAssistant:Editors.FocusKeywordEditor:suggestionsIntro', `Our page content analysis points one of the following phrases as focus keyword. If this does not fit at all, customising the text for SEO could be helpful.`)}</p>
                        {suggestions.map((suggestion) => (
                            <button
                                key={suggestion}
                                className={'neos-button neos-button-secondary'}
                                style={{marginTop: '3px', width: '100%', minHeight: '40px', height: 'auto'}}
                                disabled={disabled}
                                onClick={() => this.props.updateItemProperty(suggestion, ListItemPropertyState.AiGenerated)}>
                                {suggestion}
                            </button>
                        ))}
                    </span>}
                </p>}
                {suggestionsState === 'failed' && <Alert message={this.state.errorMessage}/>}
                {(suggestionsState === 'loaded' || property.currentValue) &&
                    <p style={{marginBottom: '16px'}}>
                        {this.translationService.translate('NEOSidekick.AiAssistant:Editors.FocusKeywordEditor:checkSearchVolume', `Check search volume:`)}
                        &nbsp;
                        <a href={ahrefsLink} target="_blank">
                            <span style={{textDecoration: 'underline'}}>Ahrefs</span>
                            &nbsp;
                            <FontAwesomeIcon icon={faExternalLinkAlt} style={{width: '10px'}}/>
                        </a>
                        &nbsp;|&nbsp;
                        <a href={googleLink} target="_blank">
                            <span style={{textDecoration: 'underline'}}>Google</span>
                            &nbsp;
                            <FontAwesomeIcon icon={faExternalLinkAlt} style={{width: '10px'}}/>
                        </a>
                    </p>
                }
            </div>
        )
    }
}

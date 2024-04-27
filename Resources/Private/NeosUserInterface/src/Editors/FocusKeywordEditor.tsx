import React, {Component} from 'react';
import {connect} from 'react-redux';
import PropTypes from 'prop-types';
import {neos} from '@neos-project/neos-ui-decorators';
import {Button, Icon} from '@neos-project/react-ui-components';
import {actions, selectors} from '@neos-project/neos-ui-redux-store';
import {TextField} from '@neos-project/neos-ui-editors';

import "./index.css";

export interface FocusKeywordEditorState {
    suggestionsState: 'none' | 'is-loading' | 'loaded',
    suggestions: string[]
}

@neos(globalRegistry => ({
    i18nRegistry: globalRegistry.get('i18n'),
    externalService: globalRegistry.get('NEOSidekick.AiAssistant').get('externalService'),
    contentService: globalRegistry.get('NEOSidekick.AiAssistant').get('contentService'),
    frontendConfiguration: globalRegistry.get('NEOSidekick.AiAssistant').get('configuration')
}))
@connect(state => {
    const node = selectors.CR.Nodes.focusedSelector(state);
    return {
        activeContentDimensions: selectors.CR.ContentDimensions.active(state),
        node: node,
        parentNode: selectors.CR.Nodes.nodeByContextPath(state)(node.parent),
    };
}, {
    addFlashMessage: actions.UI.FlashMessages.add
})
export default class FocusKeywordEditor extends Component<any, FocusKeywordEditorState> {
    static propTypes = {
        // matches TextField
        className: PropTypes.string,
        value: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
        commit: PropTypes.func.isRequired,
        options: PropTypes.object,
        onKeyPress: PropTypes.func,
        onEnterKey: PropTypes.func,
        id: PropTypes.string,

        activeContentDimensions: PropTypes.object.isRequired,
        node: PropTypes.object,
        parentNode: PropTypes.object,

        i18nRegistry: PropTypes.object.isRequired,
        externalService: PropTypes.object.isRequired,
        contentService: PropTypes.object.isRequired,
        addFlashMessage: PropTypes.func.isRequired,
        frontendConfiguration: PropTypes.object
    };

    constructor(props: any) {
        super(props);
        this.state = {
            suggestionsState: 'none',
            suggestions: []
        }
    }

    renderIcon(loading: boolean) {
        if (loading) {
            return <Icon icon="spinner" fixedWidth padded="right" spin={true} />
        } else {
            return <Icon icon="magic" fixedWidth padded="right" />
        }
    }

    private getLanguage(): string {
        const {activeContentDimensions, frontendConfiguration} = this.props;
        return activeContentDimensions.language ? activeContentDimensions.language[0] : frontendConfiguration.defaultLanguage;
    }

    private async fetchSuggestions(retries = 3): Promise<void> {
        const {externalService, contentService, addFlashMessage, i18nRegistry, node, parentNode, options} = this.props;
        const module = options.module;
        const sidekickArguments = options.arguments ?? {};
        this.setState({suggestionsState: 'is-loading'});
        try {
            // Process SidekickClientEval und ClientEval
            let userInput = await contentService.processObjectWithClientEval(sidekickArguments, node, parentNode);
            // Map to external format
            // @ts-ignore
            userInput = Object.keys(userInput).map((identifier: string) => ({'identifier': identifier, 'value': userInput[identifier]}));
            let generatedValue = await externalService.generate(module, this.getLanguage(), userInput);

            if (!Array.isArray(generatedValue) || generatedValue.length === 0) {
                if (retries === 0) {
                    throw new Error(i18nRegistry.translate('NEOSidekick.AiAssistant:Editors.FocusKeywordEditor:suggestionsFailed', 'Could not calculate focus keyword suggestions for the page.'));
                }
                this.fetchSuggestions(retries - 1);
                return;
            }

            this.setState({
                suggestionsState: 'loaded',
                suggestions: generatedValue
            });
        } catch (e) {
            this.setState({
                suggestionsState: 'none',
            });
            addFlashMessage(e?.code ?? e?.message, e?.code ? i18nRegistry.translate('NEOSidekick.AiAssistant:Error:' + e.code, e.message, {0: e.externalMessage}) : e.message, e?.severity ?? 'error')
        }
    }

    render () {
        const {value, commit, i18nRegistry} = this.props;
        const {suggestionsState, suggestions} = this.state;
        const ahrefsLink = `https://app.ahrefs.com/keywords-explorer/google/${this.getLanguage().replace('en', 'uk')}/overview?keyword=${value}`
        const googleLink = `https://ads.google.com/aw/keywordplanner/plan/keywords/historical`;

        return (
            <div style={{display: 'flex', flexDirection: 'column'}}>
                <div>
                    <TextField {...this.props}/>
                </div>
                {(suggestionsState === 'none' || suggestionsState === 'is-loading') &&
                    <div style={{marginTop: '5px'}}>
                        <Button
                            className="neosidekick__editor__generate-button"
                            size="regular"
                            icon={suggestionsState === 'is-loading' ? 'hourglass' : 'magic'}
                            style="neutral"
                            hoverStyle="clean"
                            disabled={suggestionsState === 'is-loading'}
                            onClick={() => this.fetchSuggestions()}
                        >
                            {i18nRegistry.translate('NEOSidekick.AiAssistant:Main:calculateWithSidekick', 'Calculate with Sidekick')}&nbsp;
                            {this.renderIcon(suggestionsState === 'is-loading')}
                        </Button>
                    </div>
                }
                {suggestionsState === 'loaded' &&
                    <div style={{padding: '1rem', background: 'gray', marginTop: '5px', marginBottom: '0'}}>
                        <p style={{marginTop: '5px'}}>{i18nRegistry.translate('NEOSidekick.AiAssistant:Editors.FocusKeywordEditor:suggestionsIntro', `Our page content analysis points one of the following phrases as focus keyword. If this does not fit at all, customising the text for SEO could be helpful.`)}</p>
                        {suggestions.map((suggestion) => (
                            <Button
                                key={suggestion}
                                className="neosidekick__editor__suggestion-button"
                                size="small"
                                style="lighter"
                                hoverStyle="brand"
                                onClick={() => commit(suggestion)}>
                                {suggestion}
                            </Button>
                        ))}
                    </div>
                }
                {(suggestionsState === 'loaded' || value) &&
                    <div style={{marginTop: '5px'}}>
                        {i18nRegistry.translate('NEOSidekick.AiAssistant:Editors.FocusKeywordEditor:checkSearchVolume', `Check search volume:`)}
                        &nbsp;
                        <a href={ahrefsLink} target="_blank"
                           style={{textDecoration: 'underline', color: 'white'}}>Ahrefs</a>
                        &nbsp;|&nbsp;
                        <a href={googleLink} target="_blank"
                           style={{textDecoration: 'underline', color: 'white'}}>Google</a>
                    </div>
                }
            </div>
        )
    }
}

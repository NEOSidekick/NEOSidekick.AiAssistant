import React, {Component} from 'react';
import {connect} from 'react-redux';
import PropTypes from 'prop-types';
import {neos} from '@neos-project/neos-ui-decorators';
import {Button, Icon, TextArea} from '@neos-project/react-ui-components';
import {actions, selectors} from '@neos-project/neos-ui-redux-store';

import "./index.css";

const defaultOptions = {
    disabled: false,
    maxlength: null,
    readonly: false,
    placeholder: '',
    minRows: 2,
    expandedRows: 6
};

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
export default class MagicTextAreaEditor extends Component<any, any> {
    constructor(props: any) {
        super(props);
        this.state = {loading: false}
    }
    static propTypes = {
        className: PropTypes.string,
        value: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
        commit: PropTypes.func.isRequired,
        options: PropTypes.object,
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

    static defaultProps = {
        options: {}
    };

    renderIcon(loading: boolean) {
        if (loading) {
            return <Icon icon="spinner" fixedWidth padded="right" spin={true} />
        } else {
            return <Icon icon="magic" fixedWidth padded="right" />
        }
    }

    fetch = async (module: string, userInput: object) => {
        const {commit, externalService, contentService, activeContentDimensions, addFlashMessage, i18nRegistry, node, parentNode, frontendConfiguration} = this.props;
        this.setState({loading: true});
        try {
            // Process SidekickClientEval und ClientEval
            userInput = await contentService.processObjectWithClientEval(userInput, node, parentNode)
            // Map to external format
            // @ts-ignore
            userInput = Object.keys(userInput).map((identifier: string) => ({'identifier': identifier, 'value': userInput[identifier]}));
            const lang = activeContentDimensions.language ? activeContentDimensions.language[0] : frontendConfiguration.defaultLanguage;
            const generatedValue = await externalService.generate(module, lang, userInput)
            commit(generatedValue)
        } catch (e) {
            addFlashMessage(e?.code ?? e?.message, e?.code ? i18nRegistry.translate('NEOSidekick.AiAssistant:Error:' + e.code, e.message, {0: e.externalMessage}) : e.message, e?.severity ?? 'error')
        } finally {
            this.setState({loading: false});
        }
    }

    render () {
        const {id, value, className, commit, options, i18nRegistry} = this.props;

        // Placeholder text must be unescaped in case html entities were used
        const placeholder = options && options.placeholder && i18nRegistry.translate(unescape(options.placeholder));
        const finalOptions = Object.assign({}, defaultOptions, options);
        const showGenerateButton = !finalOptions.readonly && !finalOptions.disabled;

        return (
            <div style={{display: 'flex', flexDirection: 'column'}} className={className}>
                <div>
                    <TextArea
                        id={id}
                        value={value}
                        onChange={commit}
                        disabled={finalOptions.disabled || this.state.loading}
                        maxLength={finalOptions.maxlength}
                        readOnly={finalOptions.readonly}
                        placeholder={placeholder}
                        minRows={finalOptions.minRows}
                        expandedRows={finalOptions.expandedRows}
                    />
                </div>
                {showGenerateButton ? (
                    <div>
                        <Button
                            className="neosidekick__editor__generate-button"
                            size="regular"
                            icon={this.state.loading ? 'hourglass' : 'magic'}
                            style="neutral"
                            hoverStyle="clean"
                            disabled={this.state.loading}
                            onClick={() => this.fetch(finalOptions.module, finalOptions.arguments ?? {})}
                        >
                            {i18nRegistry.translate('NEOSidekick.AiAssistant:Main:generateWithSidekick')}&nbsp;
                            {this.renderIcon(this.state.loading)}
                        </Button>
                    </div>
                ) : null}
            </div>
        );
    }
}

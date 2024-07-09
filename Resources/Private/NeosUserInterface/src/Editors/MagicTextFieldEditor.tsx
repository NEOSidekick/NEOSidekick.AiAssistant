import React, {PureComponent} from 'react';
import {connect} from 'react-redux';
import PropTypes from 'prop-types';
import {neos} from '@neos-project/neos-ui-decorators';
import {Button, Icon, TextInput} from '@neos-project/react-ui-components';
import {actions, selectors} from '@neos-project/neos-ui-redux-store';

import "./index.css";

const defaultOptions = {
    autoFocus: false,
    disabled: false,
    maxlength: null,
    readonly: false
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
        transientValues: selectors.UI.Inspector.transientValues(state),
        parentNode: selectors.CR.Nodes.nodeByContextPath(state)(node.parent),
    };
}, {
    addFlashMessage: actions.UI.FlashMessages.add
})
export default class MagicTextFieldEditor extends PureComponent<any, any> {
    constructor(props: any) {
        super(props);
        let initialPlaceholder = '';
        if (props.options?.placeholder?.startsWith('SidekickClientEval')) {
            this.fetchAndUpdatePlaceholder();
        } else if (props.options?.placeholder) {
            // Placeholder text must be unescaped in case html entities were used
            initialPlaceholder = props.i18nRegistry.translate(unescape(props.options.placeholder));
        }
        this.state = {loading: false, placeholder: initialPlaceholder};
    }
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
        transientValues: PropTypes.object,

        i18nRegistry: PropTypes.object.isRequired,
        externalService: PropTypes.object.isRequired,
        contentService: PropTypes.object.isRequired,
        addFlashMessage: PropTypes.func.isRequired,
        frontendConfiguration: PropTypes.object
    };

    static defaultProps = {
        options: {}
    };

    componentDidUpdate(prevProps) {
        this.fetchAndUpdatePlaceholderIfReferencedPropertyHasChanged(prevProps);
    }

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
            userInput = await contentService.processObjectWithClientEval(userInput, node, parentNode);
            // Map to external format
            // @ts-ignore
            userInput = Object.keys(userInput).map((identifier: string) => ({'identifier': identifier, 'value': userInput[identifier]}));
            const lang = activeContentDimensions.language ? activeContentDimensions.language[0] : frontendConfiguration.defaultLanguage;
            const generatedValue = await externalService.generate(module, lang, userInput);
            commit(generatedValue);
        } catch (e) {
            addFlashMessage(e?.code ?? e?.message, e?.code ? i18nRegistry.translate('NEOSidekick.AiAssistant:Error:' + e.code, e.message, {0: e.externalMessage}) : e.message, e?.severity ?? 'error')
        } finally {
            this.setState({loading: false});
        }
    }

    private fetchAndUpdatePlaceholderIfReferencedPropertyHasChanged = async (prevProps) => {
        const {node, transientValues, options} = this.props;

        if (!options?.placeholder?.startsWith('SidekickClientEval')) {
            return;
        }

        const pattern = /node\.properties\.(\w+)/g;
        const matches = options.placeholder.match(pattern);
        const properties = matches.map((match: string) => {
            const [, property]: RegExpMatchArray = match.match(/node\.properties\.(.*)/);
            return property;
        });

        let shouldUpdate = false;
        properties.forEach((property: string) => {
            if (transientValues?.[property] !== prevProps.transientValues?.[property] || node.properties[property] !== prevProps.node.properties[property]) {
                shouldUpdate = true;
            }
        });

        shouldUpdate && this.fetchAndUpdatePlaceholder();
    }

    private fetchAndUpdatePlaceholder = async () => {
        const {contentService, node, parentNode, options} = this.props;
        contentService
            .processClientEval(options.placeholder, node, parentNode)
            .then((placeholder: string) => this.setState({placeholder}));
    }

    render () {
        const {id, value, className, commit, options, i18nRegistry, onKeyPress, onEnterKey} = this.props;
        const {placeholder} = this.state;

        const finalOptions = Object.assign({}, defaultOptions, options);
        const showGenerateButton = !finalOptions.readonly && !finalOptions.disabled;

        return (
            <div style={{display: 'flex', flexDirection: 'column'}} className={className}>
                <div>
                    <TextInput
                        id={id}
                        autoFocus={finalOptions.autoFocus}
                        value={value === null ? '' : value}
                        onChange={commit}
                        disabled={finalOptions.disabled || this.state.loading}
                        maxLength={finalOptions.maxlength}
                        readOnly={finalOptions.readonly}
                        placeholder={placeholder}
                        onKeyPress={onKeyPress}
                        onEnterKey={onEnterKey}
                    />
                </div>
                {showGenerateButton ? (
                    <div style={{marginTop: '4px'}}>
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

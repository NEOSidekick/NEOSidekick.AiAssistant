import React, {Component} from 'react';
import {connect} from 'react-redux';
import PropTypes from 'prop-types';
import {neos} from '@neos-project/neos-ui-decorators';
import {TextInput, Icon, Button} from '@neos-project/react-ui-components';
import { actions, selectors } from '@neos-project/neos-ui-redux-store';

const defaultOptions = {
    autoFocus: false,
    disabled: false,
    maxlength: null,
    readonly: false
};

@neos(globalRegistry => ({
    i18nRegistry: globalRegistry.get('i18n'),
    externalService: globalRegistry.get('NEOSidekick.AiAssistant').get('externalService'),
    contentService: globalRegistry.get('NEOSidekick.AiAssistant').get('contentService')
}))
@connect(state => ({
    activeContentDimensions: selectors.CR.ContentDimensions.active(state)
}), {
    addFlashMessage: actions.UI.FlashMessages.add
})
export default class MagicTextFieldEditor extends Component<any, any> {
    constructor(props) {
        super(props);
    }

    static propTypes = {
        className: PropTypes.string,
        value: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
        commit: PropTypes.func.isRequired,
        options: PropTypes.object,
        onKeyPress: PropTypes.func,
        onEnterKey: PropTypes.func,
        id: PropTypes.string,

        activeContentDimensions: PropTypes.object.isRequired,

        i18nRegistry: PropTypes.object.isRequired,
        externalService: PropTypes.object.isRequired,
        contentService: PropTypes.object.isRequired,
        addFlashMessage: PropTypes.func.isRequired
    };

    static defaultProps = {
        options: {}
    };

    state = {
        loading: false
    }

    getIcon = (loading) => {
        if (loading) {
            return <Icon icon="spinner" size="" fixedWidth padded="right" spin={true} />
        } else {
            return <Icon icon="magic" size="" fixedWidth padded="right" />
        }
    }

    fetch = async (module: string, userInput: object) => {
        const {
            commit,
            externalService,
            contentService,
            activeContentDimensions,
            addFlashMessage,
            i18nRegistry
        } = this.props;
        this.setState({loading: true});
        try {
            // Process SidekickClientEval und ClientEval
            userInput = contentService.processObjectWithClientEval(Object.assign({}, userInput))
            // Map to external format
            userInput = Object.keys(userInput).map((identifier: string) => ({"identifier": identifier, "value": userInput[identifier]}))
            const generatedValue = await externalService.generate(module, activeContentDimensions.language ? activeContentDimensions.language[0] : "", userInput)
            commit(generatedValue)
        } catch (e) {
            console.error(e)
            addFlashMessage('NEOSidekick.AiAssistant', i18nRegistry.translate('NEOSidekick.AiAssistant:Main:failedToGenerate'), 'ERROR')
        } finally {
            this.setState({loading: false});
        }
    }

    render () {
        const {
            id,
            value,
            className,
            commit,
            options,
            i18nRegistry,
            onKeyPress,
            onEnterKey
        } = this.props;

        const finalOptions = Object.assign({}, defaultOptions, options);
        // Placeholder text must be unescaped in case html entities were used
        const placeholder = options && options.placeholder && i18nRegistry.translate(unescape(options.placeholder));
        const showGenerateButton = !(
            finalOptions.readonly || finalOptions.disabled
        );

        return (
            <div style={{display: 'flex', flexDirection: 'column'}} className={className}>
                <div>
                    <TextInput
                        id={id}
                        value={value}
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
                    <div>
                        <Button
                            className="generateBtn"
                            size="regular"
                            icon={this.state.loading ? 'hourglass' : 'magic'}
                            style="neutral"
                            hoverStyle="clean"
                            disabled={this.state.loading}
                            onClick={async () => await this.fetch(finalOptions.module, finalOptions.arguments ?? {})}
                        >
                            {i18nRegistry.translate('NEOSidekick.AiAssistant:Main:generate')}&nbsp;
                            {this.getIcon(this.state.loading)}
                        </Button>
                    </div>
                ) : null}
            </div>
        );
    }
}

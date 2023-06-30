import React, {Component} from 'react';
import {connect} from 'react-redux';
import PropTypes from 'prop-types';
import {neos} from '@neos-project/neos-ui-decorators';
import {TextArea, Icon, Button} from '@neos-project/react-ui-components';
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
export default class MagicTextAreaEditor extends Component {
    constructor(props) {
        super(props);
        this.state = {loading: false}
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

    getIcon = (loading) => {
        if (loading) {
            return <Icon icon="spinner" size="" fixedWidth padded="right" spin={true} />
        } else {
            return <Icon icon="magic" size="" fixedWidth padded="right" />
        }
    }

    fetch = async (module) => {
        const {
            commit,
            externalService,
            contentService,
            activeContentDimensions,
            addFlashMessage,
            i18nRegistry
        } = this.props;
        this.setState({loading: true});
        const title = contentService.getCurrentDocumentNode()?.properties?.title
        const content = contentService.getGuestFrameDocumentHtml()
        try {
            const metaDescription = await externalService.generate(module, activeContentDimensions.language ? activeContentDimensions.language[0] : "", title, content)
            commit(metaDescription)
        } catch (e) {
            console.error(e)
            addFlashMessage('NEOSidekick.AiAssistant', i18nRegistry.translate('NEOSidekick.AiAssistant:Main:failedToGenerate'), 'ERROR')
        }
        this.setState({loading: false});
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
                            onClick={async () => await this.fetch(finalOptions.module)}
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

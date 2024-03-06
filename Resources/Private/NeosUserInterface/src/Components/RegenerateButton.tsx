import React, {PureComponent} from 'react';
import {connect} from 'react-redux'
import PropTypes from 'prop-types';
import {Button, Icon} from '@neos-project/react-ui-components';
import {neos} from '@neos-project/neos-ui-decorators';
import {actions} from '@neos-project/neos-ui-redux-store';

@neos(globalRegistry => ({
    contentService: globalRegistry.get('NEOSidekick.AiAssistant').get('contentService'),
    i18nRegistry: globalRegistry.get('i18n'),
}))
@connect(() => {}, {
    addFlashMessage: actions.UI.FlashMessages.add
})
export default class RegenerateButton extends PureComponent {
    static propTypes = {
        contentService: PropTypes.object.isRequired,
        i18nRegistry: PropTypes.object.isRequired,
        addFlashMessage: PropTypes.func.isRequired,
    };

    state = {
        show: false,
        enabled: true
    }

    constructor(props) {
        super(props);
    }

    componentDidMount() {
        this.evaluateFocusedNodeAndPropertyAndShowButton()
    }

    private evaluateFocusedNodeAndPropertyAndShowButton = (retries = 5) => {
        const {nodeType, property} = this.props.contentService.getCurrentlyFocusedNodePathAndProperty()
        if (!nodeType || !property) {
            if (retries === 0) {
                return;
            }

            setTimeout(() => this.evaluateFocusedNodeAndPropertyAndShowButton(retries - 1), 100);
            return;
        }

        const propertyConfiguration = nodeType.properties[property];
        this.setState({show: propertyConfiguration?.ui?.inlineEditable === true && propertyConfiguration?.options?.sidekick?.module !== undefined})
    }

    onClick = async () => {
        const {
            addFlashMessage,
            i18nRegistry,
            contentService
        } = this.props;
        try {
            // Disable button
            this.setState({enabled: false})
            const {node, nodeType, parentNode, property} = contentService.getCurrentlyFocusedNodePathAndProperty()
            await contentService.evaluateNodeTypeConfigurationAndStartGeneration(node, property, nodeType, parentNode)
        } catch (e) {
            addFlashMessage(e?.code ?? e?.message, e?.code ? i18nRegistry.translate('NEOSidekick.AiAssistant:Error:' + e.code, e.message, {0: e.externalMessage}) : e.message, e?.severity ?? 'error')
        } finally {
            // Re-enable button after 2500ms
            setTimeout(() => {
                this.setState({enabled: true})
            }, 2500)
        }
    }

    render() {
        const {
            i18nRegistry
        } = this.props;

        return (this.state.show ? <Button disabled={!this.state.enabled} style="transparent" hoverStyle="brand" onClick={this.onClick} isActive={Boolean(this.props.isActive)} title={i18nRegistry.translate('NEOSidekick.AiAssistant:Main:generate')}>
            <Icon icon="magic" size="" fixedWidth padded="right" />
            {i18nRegistry.translate('NEOSidekick.AiAssistant:Main:generate')}
        </Button> : '');
    }
}

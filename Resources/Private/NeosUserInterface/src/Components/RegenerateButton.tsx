import React, {PureComponent} from 'react';
import PropTypes from 'prop-types';
import {Button, Icon} from '@neos-project/react-ui-components';
import {neos} from '@neos-project/neos-ui-decorators';

@neos(globalRegistry => ({
    contentService: globalRegistry.get('NEOSidekick.AiAssistant').get('contentService'),
    i18nRegistry: globalRegistry.get('i18n'),
}))
export default class RegenerateButton extends PureComponent {
    static propTypes = {
        contentService: PropTypes.object.isRequired,
        i18nRegistry: PropTypes.object.isRequired,
    };

    state = {
        show: false,
        enabled: true
    }

    constructor(props) {
        super(props);
        // We need a timeout here for the store to catch up
        // TODO: Improve
        setTimeout(() => {
            const {nodeType, property} = this.props.contentService.getCurrentlyFocusedNodePathAndProperty()
            const propertyConfiguration = nodeType.properties[property]
            this.setState({show: propertyConfiguration?.ui?.inlineEditable === true && propertyConfiguration?.options?.sidekick?.onCreate})
        }, 25)
    }

    onClick = () => {
        // Disable button
        this.setState({enabled: false})
        const {node, nodeType, parentNode, property} = this.props.contentService.getCurrentlyFocusedNodePathAndProperty()
        this.props.contentService.evaluateNodeTypeConfigurationAndStartGeneration(node, property, nodeType, parentNode)

        // Renable button after 2500ms
        setTimeout(() => {
            this.setState({enabled: true})
        }, 2500)
    }

    render() {
        const {
            i18nRegistry
        } = this.props;

        return (this.state.show ? <Button disabled={!this.state.enabled} style="transparent" hoverStyle="brand" onClick={this.onClick} isActive={Boolean(this.props.isActive)} title={i18nRegistry.translate('NEOSidekick.AiAssistant:Main:regenerate')}>
            <Icon icon="magic" size="" fixedWidth padded="right" />&nbsp;
            {i18nRegistry.translate('NEOSidekick.AiAssistant:Main:regenerate')}
        </Button> : '');
    }
}

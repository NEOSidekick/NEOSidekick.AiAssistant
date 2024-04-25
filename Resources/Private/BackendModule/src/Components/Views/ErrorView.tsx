import React from "react";
import PureComponent from "../PureComponent";

export default class ErrorView extends PureComponent<ErrorViewProps> {
    render() {
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <div style={{marginBottom: '1.5rem'}}
                     dangerouslySetInnerHTML={{__html: '<div style="background-color: #ff0000; padding: 12px; font-weight: 400; font-size: 14px; line-height: 1.4;">' + this.props.errorMessage + '</div>'}}/>
                {this.props.overviewUri ? <div className={'neos-footer'}>
                    <a className={'neos-button neos-button-secondary'} href={this.props.overviewUri}>
                        {this.translationService.translate('NEOSidekick.AiAssistant:Module:returnToOverview', 'Return to overview')}
                    </a>
                </div> : null}
            </div>
        )
    }
}

export interface ErrorViewProps {
    errorMessage: string,
    overviewUri: string,
}

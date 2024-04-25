import React from "react";
import PureComponent from "../Components/PureComponent";
import ErrorMessage from "../Components/ErrorMessage";

export default class ErrorView extends PureComponent<ErrorViewProps> {
    render() {
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <ErrorMessage message={this.props.message}/>
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
    message: string,
    overviewUri: string,
}

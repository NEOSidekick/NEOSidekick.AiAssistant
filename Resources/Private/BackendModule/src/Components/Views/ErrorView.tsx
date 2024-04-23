import React from "react";
import PureComponent from "../PureComponent";
import AppContext from "../../AppContext";

export default class ErrorView extends PureComponent {
    static contextType = AppContext

    render() {
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <div style={{marginBottom: '1.5rem'}}
                     dangerouslySetInnerHTML={{__html: '<div style="background-color: #ff0000; padding: 12px; font-weight: 400; font-size: 14px; line-height: 1.4;">' + this.context.errorMessage + '</div>'}}/>
                <div className={'neos-footer'}>
                    <a className={'neos-button neos-button-secondary'} href={this.context.overviewUri}>
                        {this.translationService.translate('NEOSidekick.AiAssistant:Module:returnToOverview', 'Return to overview')}
                    </a>
                </div>
            </div>
        )
    }
}

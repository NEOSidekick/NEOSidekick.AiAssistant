import PureComponent from "./PureComponent";
import {ExternalService} from "../Service/ExternalService";
import React from "react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faSpinner} from "@fortawesome/free-solid-svg-icons";

export default class BackendMessage extends PureComponent<BackendMessageProps, BackendMessageState> {
    constructor(props: BackendMessageProps) {
        super(props);
        this.state = {
            message: `
                <div style="background-color: #00a338; padding: 12px; font-weight: 400; font-size: 14px; line-height: 1.4;">
                    ${this.translationService.translate('NEOSidekick.AiAssistant:Main:loading', 'Loading...')}
                </div>
            `
        };
    }
    componentDidMount() {
        if (this.props.identifier) {
            const externalService = ExternalService.getInstance();
            externalService.getBackendNotification(this.props.identifier)
                .then((data) => this.setState({message: data}))
                .catch(() => this.setState({message: undefined}));
        }
    }

    render() {
        return (this.state?.message ? <div style={{marginBottom: '1.5rem', maxWidth: '80ch'}} dangerouslySetInnerHTML={{__html: this.state.message}}/> : null)
    }
}

export interface BackendMessageProps {
    identifier?: string
}

export interface BackendMessageState {
    message?: string
}

import PureComponent from "./PureComponent";
import {SidekickApiService} from "../Service/SidekickApiService";
import React from "react";

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
            const sidekickApiService = SidekickApiService.getInstance();
            sidekickApiService.getBackendNotification(this.props.identifier)
                .then((data) => this.setState({message: data}))
                .catch(() => this.setState({message: undefined}));
        }
    }

    render() {
        return (this.state?.message ? <div style={{marginBottom: '1rem', maxWidth: '80ch', borderRadius: '0.25rem', boxShadow: 'rgb(255, 255, 255) 0px 0px 0px 0px, rgba(0, 0, 0, 0.05) 0px 0px 0px 1px, rgba(0, 0, 0, 0.1) 0px 10px 15px -3px, rgba(0, 0, 0, 0.1) 0px 4px 6px -4px', overflow: 'hidden'}} dangerouslySetInnerHTML={{__html: this.state.message}}/> : null)
    }
}

export interface BackendMessageProps {
    identifier?: string
}

export interface BackendMessageState {
    message?: string
}

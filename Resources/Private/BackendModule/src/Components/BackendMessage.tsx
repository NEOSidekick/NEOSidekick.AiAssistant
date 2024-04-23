import PureComponent from "./PureComponent";
import {ExternalService} from "../Service/ExternalService";
import React from "react";

export default class BackendMessage extends PureComponent<BackendMessageProps, BackendMessageState> {
    componentDidMount() {
        if (this.props.identifier) {
            const externalService = ExternalService.getInstance();
            externalService.getBackendNotification(this.props.identifier).then((data) => this.setState({message: data}));
        }
    }

    render() {
        return (this.state?.message ? <div style={{marginBottom: '1.5rem'}} dangerouslySetInnerHTML={{__html: this.state.message}}/> : null)
    }
}

export interface BackendMessageProps {
    identifier?: string
}

export interface BackendMessageState {
    message?: string
}

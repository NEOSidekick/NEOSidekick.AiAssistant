import React, {PureComponent} from "react";
export default class ErrorMessage extends PureComponent<ErrorMessageProps> {
    render() {
        return (
            <div style={{marginBottom: '1.5rem', backgroundColor: '#ff0000', padding: '12px', fontWeight: 400, fontSize: '14px', lineHeight: 1.4}}
                 dangerouslySetInnerHTML={{__html: '' + this.props.message}}/>
        )
    }
}

export interface ErrorMessageProps {
    message: string,
}

import React, {PureComponent} from "react";

export interface AlertProps {
    type?: 'error' | 'warning' | 'info'
    message: string,
}

export default class Alert extends PureComponent<AlertProps> {
    render() {
        const type = this.props.type || 'error';
        const backgroundColor = type === 'error' ? 'var(--warning)' : type === 'warning' ? 'var(--orange)' : 'var(--blueLight)';
        return (
            <div style={{backgroundColor, marginBottom: '1.5rem', padding: '12px', fontWeight: 400, fontSize: '14px', lineHeight: 1.4}}
                 dangerouslySetInnerHTML={{__html: '' + this.props.message}}/>
        )
    }
}

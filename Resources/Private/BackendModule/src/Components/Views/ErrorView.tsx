import React from "react";
import {connect} from "react-redux";
import PropTypes from "prop-types";
import StateInterface from "../../Store/StateInterface";
import PureComponent from "../PureComponent";

@connect((state: StateInterface) => ({
    hasError: state.app.hasError,
    errorMessage: state.app.errorMessage
}))
export default class ErrorView extends PureComponent {
    static propTypes = {
        hasError: PropTypes.bool,
        errorMessage: PropTypes.string
    }

    render() {
        const {errorMessage} = this.props
        return (
            <div className={'neos-content neos-indented neos-fluid-container'}>
                <div style={{marginBottom: '1.5rem'}}
                     dangerouslySetInnerHTML={{__html: '<div style="background-color: #ff0000; padding: 12px; font-weight: 400; font-size: 14px; line-height: 1.4;">' + errorMessage + '</div>'}}/>
                <div className={'neos-footer'}> </div>
            </div>
        )
    }
}

import React from "react";
import {connect} from "react-redux";
import PropTypes from "prop-types";
import StateInterface from "../Store/StateInterface";
import PureComponent from "./PureComponent";

@connect((state: StateInterface) => ({
    hasError: state.app.hasError,
    errorMessage: state.app.errorMessage
}))
export default class ErrorMessage extends PureComponent {
    static propTypes = {
        hasError: PropTypes.bool,
        errorMessage: PropTypes.string
    }

    render() {
        const {hasError, errorMessage} = this.props
        return (hasError ?
                <div style={{marginBottom: '1.5rem'}} dangerouslySetInnerHTML={{ __html: '<div style="background-color: #ff0000; padding: 12px; font-weight: 400; font-size: 14px; line-height: 1.4;">' + errorMessage + '</div>' }}/> : null
        );
    }
}

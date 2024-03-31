// @ts-ignore
import React, {PureComponent} from 'react';
import PropTypes from "prop-types";

import "./index.css";
export default class Notice extends PureComponent {
    static propTypes = {
        children: PropTypes.object.isRequired
    };


    render() {
        return (
            <div className="neosidekick__notice">
                {this.props.children}
            </div>
        );
    }
}

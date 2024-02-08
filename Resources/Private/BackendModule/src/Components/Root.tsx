import React from "react";
import PropTypes from "prop-types";
import AssetList from "./AssetList";
import {Provider} from "react-redux";
import ConfigurationForm from "./ConfigurationForm";
import StartModuleButton from "./StartModuleButton";
import SubmitAndFetchNextButton from "./SubmitAndFetchNextButton";
import PureComponent from "./PureComponent";

export default class Root extends PureComponent {
    static propTypes = {
        store: PropTypes.object.isRequired
    }

    render() {
        const {store} = this.props;
        return (
            <Provider store={store}>
                <div className={'neos-content neos-indented neos-fluid-container'}>
                    <ConfigurationForm />
                    <AssetList />
                    <div className={'neos-footer'}>
                        <StartModuleButton />
                        <SubmitAndFetchNextButton />
                    </div>
                </div>
            </Provider>
        )
    }
}

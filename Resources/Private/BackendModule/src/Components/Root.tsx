import React from "react";
import PropTypes from "prop-types";
import ItemsList from "./ItemsList";
import {Provider} from "react-redux";
import StartModuleButton from "./StartModuleButton";
import SubmitAndFetchNextButton from "./SubmitAndFetchNextButton";
import PureComponent from "./PureComponent";
import ErrorMessage from "./ErrorMessage";
import ConfigurationForm from "./ConfigurationForm";
import ReturnToOverviewButton from "./ReturnToOverviewButton";
import {Store} from "redux";
import {Endpoints} from "../Model/Endpoints";

export default class Root extends PureComponent {
    static propTypes = {
        store: PropTypes.object.isRequired,
        endpoints: PropTypes.object.isRequired
    }

    render() {
        const {store, endpoints}: {store: Store, endpoints: Endpoints} = this.props;
        return (
            <Provider store={store}>
                <div className={'neos-content neos-indented neos-fluid-container'}>
                    <ErrorMessage />
                    <ConfigurationForm/>
                    <ItemsList />
                    <div className={'neos-footer'}>
                        <StartModuleButton />
                        <SubmitAndFetchNextButton />
                        <ReturnToOverviewButton href={endpoints.overview} />
                    </div>
                </div>
            </Provider>
        )
    }
}

import PureComponent from "../PureComponent";
import React from "react";
import {PropertyInterface, PropertyState} from "../../Model/PropertiesCollection";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faSpinner} from "@fortawesome/free-solid-svg-icons";
import {ExternalService} from "../../Service/ExternalService";

export default class TextareaEditor extends PureComponent<TextareaEditorProps> {
    private handleChange(event) {
        this.props.updateItemProperty(event.target.value, PropertyState.UserManipulated)
    }

    private async generate() {
        this.props.updateItemProperty(this.props.property.currentValue, PropertyState.Generating)
        const externalService = ExternalService.getInstance()
        const generatedValue = await externalService.generate(
            this.props.sidekickConfiguration.module,
            this.props.sidekickConfiguration.language,
            this.props.sidekickConfiguration.userInput
        )
        this.props.updateItemProperty(generatedValue, PropertyState.AiGenerated)
    }

    render () {
        const id = 'field-' + (Math.random() * 1000)
        return <div className={'neos-control-group'}>
            <label className={'neos-control-label'} htmlFor={id}>{this.props.label}</label>
            <div className={'neos-controls'}>
                <textarea
                    id={id}
                    style={{width: '100%'}}
                    value={this.props.property.currentValue}
                    rows={5}
                    onChange={(e) => this.handleChange(e)}
                    disabled={this.props.disabled}/>
                <button
                    className={'neos-button neos-button-secondary'}
                    style={{marginTop: '3px', width: '100%'}}
                    disabled={this.props.disabled}
                    onClick={() => this.generate()}>
                    {this.props.property.state === PropertyState.Generating ? <span>
                        <FontAwesomeIcon icon={faSpinner} spin={true}/>&nbsp;
                        {this.translationService.translate('NEOSidekick.AiAssistant:Module:generating', 'Generating...')}
                    </span> : this.translationService.translate('NEOSidekick.AiAssistant:Module:generate', 'Generate')}
                </button>
            </div>
        </div>
    }
}

export interface TextareaEditorProps {
    label: string,
    disabled: boolean,
    updateItemProperty: Function,
    property: PropertyInterface,
    sidekickConfiguration: object
}

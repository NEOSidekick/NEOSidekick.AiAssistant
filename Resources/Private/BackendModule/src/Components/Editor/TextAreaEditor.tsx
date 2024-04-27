import PureComponent from "../PureComponent";
import React from "react";
import {ListItemProperty, ListItemPropertyState, PropertySchema} from "../../Model/ListItemProperty";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faMagic, faSpinner} from "@fortawesome/free-solid-svg-icons";
import {SidekickApiService} from "../../Service/SidekickApiService";
import { ContentService } from "../../Service/ContentService";
import {DocumentNodeListItem} from "../../Model/ListItem";

export interface TextAreaEditorProps {
    disabled: boolean,
    property: ListItemProperty,
    propertySchema: PropertySchema,
    item: DocumentNodeListItem,
    updateItemProperty: (value: string, state: ListItemPropertyState) => void,
    sidekickConfiguration?: TextAreaEditorSidekickConfiguration,
    showGenerateButton?: boolean,
    marginBottom?: string,
}

export interface TextAreaEditorState {
    placeholder: string,
    generatedChoices?: string[]
}

export default class TextAreaEditor extends PureComponent<TextAreaEditorProps,TextAreaEditorState> {
    constructor(props: TextAreaEditorProps) {
        super(props);
        this.state = {
            placeholder: ''
        }
    }

    async componentDidUpdate(prevProps: Readonly<TextAreaEditorProps>, prevState: Readonly<{}>, snapshot?: any) {
        // need to update on every item.property change
        let placeholder = this.props.propertySchema.ui?.inspector?.editorOptions?.placeholder;
        if (placeholder && placeholder.includes('ClientEval')) {
            placeholder = await ContentService.getInstance().processClientEvalFromDocumentNodeListItem(placeholder, this.props.item);
        }
        this.setState({placeholder});
    }

    private handleChange(event: any) {
        this.props.updateItemProperty(event.target.value, ListItemPropertyState.UserManipulated)
    }

    private async generate() {
        // set to generating state
        this.props.updateItemProperty(this.props.property.currentValue, ListItemPropertyState.Generating);
        this.setState({generatedChoices: undefined});

        const {module, userInput} = await this.getSidekickConfiguration();
        const generatedValue = await SidekickApiService.getInstance().generate(module, this.props.item.language, userInput);
        if (Array.isArray(generatedValue)) {
            this.setState({generatedChoices: generatedValue});
            this.props.updateItemProperty(this.props.property.currentValue, ListItemPropertyState.UserManipulated);
        } else {
            this.props.updateItemProperty(generatedValue, ListItemPropertyState.AiGenerated);
        }
    }

    // Same as in FocusKeywordEditor
    protected async getSidekickConfiguration(): Promise<TextAreaEditorSidekickConfiguration> {
        if (this.props.sidekickConfiguration) {
            return this.props.sidekickConfiguration;
        }

        const {item, propertySchema} = this.props;
        const editorOptions = propertySchema.ui?.inspector?.editorOptions;

        // Similar to MagicTextAreaEditor.fetch
        try {
            // Process SidekickClientEval und ClientEval
            const processedArguments = await ContentService.getInstance().processObjectWithClientEvalFromDocumentNodeListItem(editorOptions.arguments, item);
            // Map to external format
            // @ts-ignore
            const userInput = Object.keys(processedArguments).map((identifier: string) => ({
                'identifier': identifier,
                'value': userInput[identifier]
            })) as TextAreaEditorSidekickConfigurationSingleUserInput[];
            return {module: editorOptions.module, userInput};
        } catch (e) {
            alert(e?.code ? this.translationService.translate('NEOSidekick.AiAssistant:Error:' + e.code, e.message, {0: e.externalMessage}) : e.message);
        }
    }

    renderIcon(loading: boolean) {
        if (loading) {
            return <FontAwesomeIcon icon={faSpinner} spin={true}/>
        } else {
            return <FontAwesomeIcon icon={faMagic}/>
        }
    }

    render () {
        const {property, propertySchema, disabled, showGenerateButton, marginBottom} = this.props;
        const {placeholder, generatedChoices} = this.state;
        const maxlength = propertySchema.validation ? propertySchema.validation['Neos.Neos/Validation/StringLengthValidator']?.maximum : null;
        const id = 'field-' + (Math.random() * 1000);

        const textAreaStyle = property.initialValue === property.currentValue ? {} : {
            boxShadow: '0 0 0 2px #ff8700',
            borderRadius: '3px'
        };

        return (
            <div className={'neos-control-group'} style={{marginBottom: marginBottom || '16px'}}>
                <label className={'neos-control-label'} htmlFor={id}>{propertySchema.ui.label}</label>
                <div className={'neos-controls'}>
                    <textarea
                        id={id}
                        className={property.initialValue !== property.currentValue ? 'textarea--highlight' : ''}
                        style={Object.assign(textAreaStyle, {width: '100%', padding: '10px 14px'})}
                        value={property.currentValue || ''}
                        rows={3}
                        onChange={(e) => this.handleChange(e)}
                        disabled={disabled}
                        maxLength={maxlength}
                        placeholder={placeholder}
                    />
                    {(generatedChoices || []).map((choice, index) => (
                        <button
                            className={'neos-button neos-button-secondary'}
                            style={{marginTop: '3px', width: '100%'}}
                            onClick={() => this.props.updateItemProperty(choice, ListItemPropertyState.AiGenerated)}>
                            {choice}
                        </button>
                    ))}
                    {showGenerateButton ? <button
                        className={'neos-button neos-button-secondary'}
                        style={{marginTop: '3px', width: '100%'}}
                        disabled={disabled}
                        onClick={() => this.generate()}>
                        {this.translationService.translate('NEOSidekick.AiAssistant:Main:generateWithSidekick', 'Generate with Sidekick')}&nbsp;
                        {this.renderIcon(property.state === ListItemPropertyState.Generating)}
                    </button> : null}
                </div>
            </div>
        )
    }
}

export interface TextAreaEditorSidekickConfiguration {
    module ? : string,
    userInput: TextAreaEditorSidekickConfigurationSingleUserInput[]
}

export interface TextAreaEditorSidekickConfigurationSingleUserInput {
    identifier: string,
    value: string
}

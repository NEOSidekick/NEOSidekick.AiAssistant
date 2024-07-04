import PureComponent from "../PureComponent";
import React from "react";
import {ListItemProperty, ListItemPropertyState, PropertySchema} from "../../Model/ListItemProperty";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faMagic, faSpinner} from "@fortawesome/free-solid-svg-icons";
import {SidekickApiService} from "../../Service/SidekickApiService";
import { ContentService } from "../../Service/ContentService";
import {DocumentNodeListItem, ListItemState} from "../../Model/ListItem";
import Alert from "../Alert";
import AppContext, {AppContextType} from "../../AppContext";
import {DocumentNodeModuleConfiguration} from "../../Model/ModuleConfiguration";

export interface TextAreaEditorProps {
    disabled: boolean,
    property: ListItemProperty,
    propertySchema?: PropertySchema,
    item: DocumentNodeListItem,
    htmlContent?: string
    updateItemProperty: (value: string, state: ListItemPropertyState) => void,
    sidekickConfiguration?: TextAreaEditorSidekickConfiguration,
    autoGenerateIfActionsMatch?: boolean,
    showGenerateButton?: boolean,
    rows?: number,
    marginBottom?: string,
}

export interface TextAreaEditorState {
    placeholder: string,
    generatedChoices?: string[],
    errorMessage?: string,
    startGenerationOnHtmlContentReady?: boolean,
}

export default class TextAreaEditor extends PureComponent<TextAreaEditorProps,TextAreaEditorState> {
    static contextType = AppContext;
    context: AppContextType;

    constructor(props: TextAreaEditorProps) {
        super(props);
        this.state = {
            placeholder: ''
        }
    }

    async componentDidMount() {
        if (this.props.autoGenerateIfActionsMatch) {
            const {actions} = this.context.moduleConfiguration as DocumentNodeModuleConfiguration;
            let matches = false;
            for (const key in actions) {
                if (actions.hasOwnProperty(key)) {
                    const action = actions[key];
                    if (!matches && action.active && action.propertyName === this.props.property.propertyName) {
                        matches = await ContentService.getInstance().processClientEvalFromDocumentNodeListItem(action.clientEval, this.props.item, '');
                    }
                }
            }
            if (matches) {
                this.generateValue().then(() => {});
            }
        }
    }

    async componentDidUpdate(prevProps: Readonly<TextAreaEditorProps>, prevState: Readonly<{}>, snapshot?: any) {
        // need to update on every item.property change
        let placeholder = this.props.propertySchema?.ui?.inspector?.editorOptions?.placeholder;
        if (placeholder && placeholder.includes('ClientEval')) {
            placeholder = await ContentService.getInstance().processClientEvalFromDocumentNodeListItem(placeholder, this.props.item, '');
        }
        if (placeholder) {
            placeholder = this.translationService.translate(placeholder, placeholder);
        }
        this.setState({placeholder});

        // as soon as the htmlContent is available, we can generate the value
        if (!prevProps.htmlContent && this.props.htmlContent && this.state.startGenerationOnHtmlContentReady) {
            this.setState({startGenerationOnHtmlContentReady: false});
            this.generateValue().then(() => {});
        }
    }

    private handleChange(event: any) {
        this.props.updateItemProperty(event.target.value, ListItemPropertyState.UserManipulated)
    }

    private async generateValue() {
        // set to generating state
        this.props.updateItemProperty(this.props.property.currentValue, ListItemPropertyState.Generating);
        this.setState({generatedChoices: undefined});

        if (!this.props.htmlContent) {
            this.setState({startGenerationOnHtmlContentReady: true});
            return; // will start in componentDidUpdate
        }

        try {
            const {module, userInput} = await this.getSidekickConfiguration();
            const generatedValue = await SidekickApiService.getInstance().generate(module, this.props.item.language, userInput);
            if (Array.isArray(generatedValue)) {
                this.setState({generatedChoices: generatedValue});
                this.props.updateItemProperty(this.props.property.currentValue, ListItemPropertyState.UserManipulated);
            } else {
                this.props.updateItemProperty(generatedValue, ListItemPropertyState.AiGenerated);
            }
        } catch (e) {
            this.props.updateItemProperty(this.props.property.currentValue, ListItemPropertyState.Initial);
            this.setState({errorMessage: this.translationService.fromError(e)});
        }
    }

    // Same as in FocusKeywordEditor
    protected async getSidekickConfiguration(): Promise<TextAreaEditorSidekickConfiguration> {
        if (this.props.sidekickConfiguration) {
            return this.props.sidekickConfiguration;
        }

        const {item, propertySchema, htmlContent} = this.props;
        const editorOptions = propertySchema?.ui?.inspector?.editorOptions;

        // Similar to MagicTextAreaEditor.fetch
        try {
            // Process SidekickClientEval und ClientEval
            const processedArguments = await ContentService.getInstance().processObjectWithClientEvalFromDocumentNodeListItem(editorOptions.arguments, item, htmlContent);
            // Map to external format
            // @ts-ignore
            const userInput = Object.keys(processedArguments).map((identifier: string) => ({
                'identifier': identifier,
                'value': processedArguments[identifier]
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

    getLabel() {
        const {propertySchema} = this.props;
        const label =  propertySchema?.ui?.label;
        const translation = this.translationService.translate(label, label);

        // improve SEO property names, if they are still the defaults
        if (label === 'Neos.Seo:NodeTypes.SeoMetaTagsMixin:properties.titleOverride') {
            const betterNames = {
                'Title Override': 'SEO Page Title (Title Override)',
                'Titel überschreiben': 'SEO-Seitentitel (Titel überschreiben)',
            };
            return betterNames[translation] || translation;
        }
        if (label === 'Neos.Seo:NodeTypes.SeoMetaTagsMixin:properties.metaDescription') {
            const betterNames = {
                'Description': ' Meta Description',
                'Beschreibung': 'Meta-Beschreibung',
            };
            return betterNames[translation] || translation;
        }

        return translation;
    }

    render () {
        const {item, property, propertySchema, disabled, showGenerateButton, rows, marginBottom} = this.props;
        const {placeholder, generatedChoices, errorMessage} = this.state;
        const maxlength = propertySchema?.validation ? propertySchema.validation['Neos.Neos/Validation/StringLengthValidator']?.maximum : null;
        const id = 'field-' + (Math.random() * 1000);

        let textAreaStyle = {width: '100%', padding: '10px 14px'};
        if (property.initialValue !== property.currentValue) {
            textAreaStyle = Object.assign(textAreaStyle, {
                boxShadow: '0 0 0 2px #ff8700',
                borderRadius: '3px',
            });
            if (item.state === ListItemState.Persisted) {
                textAreaStyle = Object.assign(textAreaStyle, {
                    boxShadow: 'none',
                    background: 'var(--colors-Success)',
                });
            }
        }

        return (
            <div className={'neos-control-group'} style={{marginBottom: marginBottom || '16px'}}>
                <label className={'neos-control-label'} htmlFor={id}>{this.getLabel()}</label>
                <div className={'neos-controls'} style={{position: 'relative'}}>
                    {property.state == ListItemPropertyState.Generating && <FontAwesomeIcon icon={faSpinner} spin={true} style={{position: 'absolute', inset: '12px'}}/>}
                    <textarea
                        id={id}
                        className={property.initialValue !== property.currentValue ? 'textarea--highlight' : ''}
                        style={textAreaStyle}
                        value={property.currentValue || ''}
                        rows={rows || 3}
                        onChange={(e) => this.handleChange(e)}
                        disabled={disabled}
                        maxLength={maxlength}
                        placeholder={property.state != ListItemPropertyState.Generating && placeholder}
                    />
                    {(generatedChoices || []).map((suggestion, index) => (
                        <button
                            key={suggestion}
                            className={'neos-button neos-button-secondary'}
                            style={{marginTop: '3px', width: '100%'}}
                            onClick={() => this.props.updateItemProperty(suggestion, ListItemPropertyState.AiGenerated)}>
                            {suggestion}
                        </button>
                    ))}
                    {showGenerateButton && <button
                        className={'neos-button neos-button-secondary'}
                        style={{marginTop: '3px', width: '100%'}}
                        disabled={disabled}
                        onClick={() => this.generateValue()}>
                        {this.translationService.translate('NEOSidekick.AiAssistant:Main:generateWithSidekick', 'Generate with Sidekick')}&nbsp;
                        {this.renderIcon(property.state === ListItemPropertyState.Generating)}
                    </button>}
                    {errorMessage && <Alert message={errorMessage}/>}
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

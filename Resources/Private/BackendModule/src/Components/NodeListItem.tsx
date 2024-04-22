import PureComponent from "./PureComponent";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faCheck, faExternalLinkAlt, faSpinner} from "@fortawesome/free-solid-svg-icons";
import React, {RefObject} from "react";
import {ListItemState} from "../Enums/ListItemState";
import {PropertyState} from "../Model/PropertiesCollection";
import {Draft, produce} from "immer";
import {StatefulModuleItem} from "../Model/StatefulModuleItem";
import TextareaEditor from "./Editor/TextareaEditor";

export default class NodeListItem extends PureComponent<NodeListItemProps, NodeListItemState> {
    private readonly iframeRef: RefObject<any>;

    constructor(props) {
        super(props);
        this.state = {
            pageContent: null
        }
        this.iframeRef = React.createRef();
        // noinspection JSIgnoredPromiseFromCall
        this.fetchIframeContent()
    }

    private async fetchIframeContent() {
        const {item} = this.props
        const pageContentResponse = await fetch(item.publicUri)
        const pageContent = await pageContentResponse.text()
        this.setState({
            pageContent
        })
    }

    private updateItemProperty(propertyName: string, propertyValue: any, state: PropertyState) {
        const {updateItem} = this.props;
        updateItem(produce(this.props.item, (draft: Draft<StatefulModuleItem>) => {
            draft.properties[propertyName] = {
                ...draft.properties[propertyName],
                state: (state === PropertyState.UserManipulated && draft.properties.focusKeyword.initialValue === propertyValue) ? PropertyState.Initial : state,
                currentValue: propertyValue
            };
        }));
    }

    private discard(): void
    {
        const {updateItem} = this.props;
        updateItem(produce(this.props.item, (draft: Draft<StatefulModuleItem>) => {
            draft.properties = Object.keys(draft.properties).reduce((accumulator, propertyName) => {
                return {
                    ...accumulator,
                    [propertyName]: {
                        ...draft.properties[propertyName],
                        state: PropertyState.Initial,
                        currentValue: draft.properties[propertyName].initialValue,
                    }
                }
            }, {});
        }));
    }

    private canChangeValue(): boolean
    {
        const {item} = this.props;
        return item.state === ListItemState.Initial && !Object.values(item.properties).find(property => property.state === PropertyState.Generating)
    }

    private canDiscard(): boolean
    {
        const {item} = this.props;
        return item.state === ListItemState.Initial && !Object.values(item.properties).find(property => property.state === PropertyState.Initial || property.state === PropertyState.Generating)
    }

    private canPersist(): boolean
    {
        const {item} = this.props;
        return item.state === ListItemState.Initial && !Object.values(item.properties).find(property => property.state === PropertyState.Generating)
    }

    private renderSaveButtonLabel() {
        const {item} = this.props;
        if (item.state === ListItemState.Persisting) {
            return (
                <span>
                    <FontAwesomeIcon icon={faSpinner} spin={true}/>
                    &nbsp;
                    {this.translationService.translate('NEOSidekick.AiAssistant:Module:persisting', 'Saving...')}
                </span>
            )
        } else if (item.state === ListItemState.Persisted) {
            return (
                <span>
                    <FontAwesomeIcon icon={faCheck} />
                    &nbsp;
                    {this.translationService.translate('NEOSidekick.AiAssistant:Module:persisted', 'Saved')}
                </span>
            )
        } else {
            return (
                <span>
                    {this.translationService.translate('NEOSidekick.AiAssistant:Module:persist', 'Save')}
                </span>
            )
        }
    }

    componentDidUpdate(prevProps: Readonly<NodeListItemProps>, prevState: Readonly<NodeListItemState>) {
        if (!prevState.pageContent && this.state.pageContent) {
            const iframe = this.iframeRef.current
            iframe.contentWindow.document.open()
            iframe.contentWindow.document.write(this.state.pageContent)
            iframe.contentWindow.document.close()
        }
    }

    render() {
        const { item, persist, generate } = this.props;
        const ahrefsLink = `https://app.ahrefs.com/keywords-explorer/google/${item.language.toLowerCase().replace('en', 'uk')}/overview?keyword=${item.focusKeyword}`
        const googleLink = `https://ads.google.com/aw/keywordplanner/plan/keywords/historical`
        // todo refactor
        const sidekickConfiguration = {
            'module': 'free_conversation',
            'userInput': [
                {
                    identifier: 'content',
                    value: 'Answer with: example.'
                }
            ],
            'language': this.props.item.language
        }
        return (
            <div className={'neos-row-fluid'} style={{marginBottom: '2rem', opacity: (item.state === ListItemState.Persisted ? '0.5' : '1')}}>
                <div className={'neos-span8'}>
                    <iframe ref={this.iframeRef} src="about:blank" style={{aspectRatio: '3 / 2', width: '100%'}} />
                </div>
                <div className={'neos-span4'}>
                    <h2 style={{marginBottom: '1rem'}}>{this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:listItem.label', 'Page »' + item.pageTitle + '«', {0: item.pageTitle})}</h2>
                    <p>
                        <a href={item.publicUri} target="_blank">
                            {item.publicUri}&nbsp;
                            <FontAwesomeIcon icon={faExternalLinkAlt} />
                        </a>
                    </p>
                    <br />
                    {item.state === ListItemState.Generated ?
                        <div>
                            <p style={{padding: '1rem', background: 'gray'}}>
                                {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:listItem.notice', `Our page content analysis points to the focus keyword "${item.focusKeyword}". If this does not fit at all, customising the text for SEO could be helpful.`, {0: item.focusKeyword})}
                            </p>
                            <br/>
                        </div> : null
                    }
                    <TextareaEditor
                        label={'Focus-Keyword'}
                        disabled={!this.canChangeValue()}
                        updateItemProperty={(value: string, state: PropertyState) => this.updateItemProperty('focusKeyword', value, state)}
                        property={item.properties.focusKeyword}
                        sidekickConfiguration={sidekickConfiguration}/>
                    <p>
                        {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:listItem.checkSearchVolume', `Check search volume:`)}
                        &nbsp;
                        <a href={ahrefsLink} target="_blank">
                            <span style={{textDecoration: 'underline'}}>Ahrefs</span>
                            &nbsp;
                            <FontAwesomeIcon icon={faExternalLinkAlt} />
                        </a>
                        &nbsp;|&nbsp;
                        <a href={googleLink} target="_blank">
                            <span style={{textDecoration: 'underline'}}>Google</span>
                            &nbsp;
                            <FontAwesomeIcon icon={faExternalLinkAlt} />
                        </a>
                    </p>
                    <br />
                    <div>
                        <button
                            className={'neos-button neos-button-danger'}
                            style={{marginRight: '8px'}}
                            disabled={!this.canDiscard()}
                            onClick={() => this.discard()}>
                            {this.translationService.translate('NEOSidekick.AiAssistant:Module:discard', 'Discard')}
                        </button>
                        <button
                            className={'neos-button neos-button-success'}
                            style={{marginRight: '8px'}}
                            disabled={!this.canPersist()}
                            onClick={() => this.props.persistItem(this.props.item)}>{this.renderSaveButtonLabel()}
                        </button>
                    </div>
                </div>
            </div>
        )
    }
}

export interface NodeListItemProps {
    item: StatefulModuleItem,
    persistItem: Function,
    updateItem: Function,
}

export interface NodeListItemState {
    pageContent: string
}

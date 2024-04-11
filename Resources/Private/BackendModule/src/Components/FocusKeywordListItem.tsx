import PureComponent from "./PureComponent";
import {connect} from "react-redux";
import {generateItem, persistOneItem, updateItemProperty} from "../Store/AppSlice";
import PropTypes from "prop-types";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faCheck, faSpinner, faExternalLinkAlt} from "@fortawesome/free-solid-svg-icons";
import React from "react";

@connect(null, (dispatch, ownProps) => ({
    update(propertyValue: string) {
        dispatch(updateItemProperty({ identifier: ownProps.item.identifier, propertyName: 'focusKeyword', propertyValue }))
    },
    persist() {
        dispatch(persistOneItem(ownProps.item.identifier))
    },
    generate() {
        dispatch(generateItem(ownProps.item))
    }
}))
export default class FocusKeywordListItem extends PureComponent {
    static propTypes = {
        item: PropTypes.object.isRequired,
        update: PropTypes.func,
        persist: PropTypes.func,
        generate: PropTypes.func
    }
    private readonly iframeRef;

    constructor(props) {
        super(props);
        this.iframeRef = React.createRef();
    }

    handleChange(event) {
        const {update} = this.props;
        update(event.target.value)
    }

    discard(): void
    {
        const {update} = this.props;
        update('')
    }

    canChangeValue(): boolean
    {
        const {item} = this.props;
        return !(item.persisted || item.generating || item.persisting);
    }

    canDiscard(): boolean
    {
        const {item} = this.props;
        return !(item.persisted || item.generating || item.persisting || item.focusKeyword === '');
    }

    canPersist(): boolean
    {
        const {item} = this.props;
        return !(item.persisted || item.generating || item.persisting || item.focusKeyword === '');
    }

    canGenerate(): boolean
    {
        const {item} = this.props;
        return !(item.persisted || item.generating || item.persisting);
    }

    saveButtonLabel() {
        const {item} = this.props;
        if (item.persisting) {
            return (
                <span>
                    <FontAwesomeIcon icon={faSpinner} spin={true}/>
                    &nbsp;
                    {this.translationService.translate('NEOSidekick.AiAssistant:Module:persisting', 'Saving...')}
                </span>
            )
        } else if (item.persisted) {
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

    render() {
        const { item, persist, generate } = this.props;
        const textfieldId = item.identifier
        if (item.pageContent) {
            const iframe = this.iframeRef.current
            iframe.contentWindow.document.open()
            iframe.contentWindow.document.write(item.pageContent)
            iframe.contentWindow.document.close()
        }
        const ahrefsLink = `https://app.ahrefs.com/keywords-explorer/google/${item.language.toLowerCase().replace('en', 'uk')}/overview?keyword=${item.focusKeyword}`
        const googleLink = `https://ads.google.com/aw/keywordplanner/plan/keywords/historical`
        return (
            <div className={'neos-row-fluid'} style={{marginBottom: '2rem', opacity: (item.persisted ? '0.5' : '1')}}>
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
                    <p style={{padding: '1rem', background: 'gray'}}>
                        {this.translationService.translate('NEOSidekick.AiAssistant:FocusKeywordModule:listItem.notice', `Our page content analysis points to the focus keyword "${item.focusKeyword}". If this does not fit at all, customising the text for SEO could be helpful.`, {0: item.focusKeyword})}
                    </p>
                    <br />
                    <div className={'neos-control-group'}>
                        <label className={'neos-control-label'} htmlFor={textfieldId}>Focus-Keyword</label>
                        <div className={'neos-controls'}>
                            <textarea
                                id={textfieldId}
                                className={'neos-span12'}
                                value={item.focusKeyword}
                                rows={5}
                                onChange={this.handleChange.bind(this)}
                                disabled={!this.canChangeValue()} />
                        </div>
                    </div>
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
                            onClick={this.discard.bind(this)}>
                            {this.translationService.translate('NEOSidekick.AiAssistant:Module:discard', 'Discard')}
                        </button>
                        <button
                            className={'neos-button neos-button-success'}
                            style={{marginRight: '8px'}}
                            disabled={!this.canPersist()}
                            onClick={persist}>{this.saveButtonLabel()}
                        </button>
                        <button
                            className={'neos-button neos-button-secondary'}
                            style={{marginRight: '8px'}}
                            disabled={!this.canGenerate()}
                            onClick={generate}>
                            {item.generating ? <span>
                                <FontAwesomeIcon icon={faSpinner} spin={true}/>&nbsp;{this.translationService.translate('NEOSidekick.AiAssistant:Module:generating', 'Generating...')}
                            </span> : this.translationService.translate('NEOSidekick.AiAssistant:Module:generate', 'Generate')}
                        </button>
                    </div>
                </div>
            </div>
        )
    }
}

import React, {PureComponent} from 'react';
import PropTypes from "prop-types";
import {neos} from "@neos-project/neos-ui-decorators";
import {SynchronousRegistry} from "@neos-project/neos-ui-extensibility";

import "./CKEditor.css";

interface CKEditorProps {
    secondaryEditorsRegistry?: SynchronousRegistry,
    value: string;
    onChange?: (value: string) => void;
    options: object;
    disabled?: boolean;
}
/**
 * Neos UI does not expose the DecoupledEditor and only exposes CKEditorWrap with a very limited API.
 *
 * As a workaround, we extend the API in a hacky way.
 */
@neos(globalRegistry => ({
    secondaryEditorsRegistry: globalRegistry.get('inspector').get('secondaryEditors'),
}))
export default class CKEditor extends PureComponent<CKEditorProps> {
    static propTypes = {
        value: PropTypes.string.isRequired,
        onChange: PropTypes.func,
        options: PropTypes.object,
        disabled: PropTypes.bool,
    };
    private readonly elementRef: React.RefObject<HTMLDivElement>;
    private editorInstance: object | undefined;
    private lastValue: string | undefined;

    constructor(props: CKEditorProps) {
        super(props);
        this.elementRef = React.createRef();
    }

    componentDidUpdate(prevProps: any) {
        if (prevProps.value === this.props.value) {
            return;
        }
        if (this.lastValue === this.props.value) {
            return;
        }

        // CKEditorWrap only pushes an initial value, we need two-way binding
        if (!this.editorInstance) {
            const elementRef = this.elementRef.current;
            // @ts-ignore
            this.editorInstance = elementRef?.querySelector('.ck-content')?.ckeditorInstance;
            if (!this.editorInstance) {
                return; // not yet initialized
            }
        }
        // @ts-ignore
        this.editorInstance.setData(this.props.value);
        this.lastValue = this.props.value;
    }

    render() {
        const {secondaryEditorsRegistry} = this.props;
        const {component: CKEditorWrap} = secondaryEditorsRegistry.get(
            'Neos.Neos/Inspector/Secondary/Editors/CKEditorWrap'
        );

        return (
            <div className={'neosidekick__ck-editor' + (this.props.disabled ? ' neosidekick__ck-editor--disabled' : '')} ref={this.elementRef}>
                <CKEditorWrap
                    onChange={(value: string) => {
                        // events that bubble up should not go down again
                        if(this.lastValue !== value) {
                            this.lastValue = value;
                            if (this.props.onChange) this.props.onChange(value);
                        }
                    }}
                    value={this.props.value}
                    options={Object.assign({}, this.props.options, {disabled: true})}
                />
            </div>
        );
    }
}

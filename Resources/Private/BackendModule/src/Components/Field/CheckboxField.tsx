import PureComponent from "../PureComponent";
import React, { ChangeEvent } from "react";

interface CheckboxFieldProps {
    label: string,
    value?: string | number,
    checked: boolean,
    onChange: (event: ChangeEvent<HTMLInputElement>) => void,
    disabled?: boolean,
}
export default class CheckboxField extends PureComponent<CheckboxFieldProps> {
    render() {
        const {label, value, checked, onChange, disabled} = this.props;
        return (
            <div className={'neos-control-group'}>
                <label className="neos-checkbox">
                    <input
                        type="checkbox"
                        onChange={onChange}
                        checked={checked}
                        disabled={disabled}
                        value={value || '1'}
                    />
                    <span></span>
                    {label}
                </label>
            </div>
        )
    }
}

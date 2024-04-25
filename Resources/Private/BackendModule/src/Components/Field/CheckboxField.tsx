import PureComponent from "../PureComponent";
import React, { ChangeEvent } from "react";

interface CheckboxFieldProps {
    label: string,
    value: string | number,
    checked: boolean,
    onChange: (event: ChangeEvent<HTMLInputElement>) => void,
}
export default class CheckboxField extends PureComponent<CheckboxFieldProps> {
    render() {
        const {label, value, checked, onChange} = this.props;
        return (
            <div className={'neos-control-group'}>
                <label className="neos-checkbox">
                    <input
                        type="checkbox"
                        onChange={onChange}
                        checked={checked}
                        value={value || '1'}
                    />
                    <span></span>
                    {label}
                </label>
            </div>
        )
    }
}

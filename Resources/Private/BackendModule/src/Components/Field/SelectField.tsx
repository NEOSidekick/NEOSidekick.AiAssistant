import PureComponent from "../PureComponent";
import React, { ChangeEvent } from "react";

interface SelectFieldProps {
    label: string,
    value: string | number,
    defaultValue?: string | number,
    options: Options,
    onChange: (event: ChangeEvent<HTMLSelectElement>) => void,
}
interface Options {
    [key: number | string]: string;
}
export default class SelectField extends PureComponent<SelectFieldProps> {
    render() {
        const {label, value, defaultValue, options, onChange} = this.props;
        return (
            <div className={'neos-control-group'}>
                <label className={'neos-control-label'}>{label}</label>
                <div className={'neos-controls'}>
                    <select
                        value={value}
                        onChange={onChange}
                        defaultValue={defaultValue}>
                        {Object.keys(options).map(key => <option value={key}>{options[key]}</option>)}
                    </select>
                </div>
            </div>
        )
    }
}

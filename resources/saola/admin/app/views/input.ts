import type {Application, View} from '@saolabs/client';
import type {InputValue, ResourceField} from '../resource-manager';
import {bindViewState} from './binding';

interface InputProps {
    field: ResourceField; locked: boolean; error: string;
    onChange: (key: string, value: InputValue) => void;
    onMedia: (key: string) => void;
    mediaAvailable: boolean;
}

/** Events, value conversion and editor tools shared by every input template/theme. */
export function bindInput(view: View, App: Application, props: () => InputProps) {
    const state = bindViewState<{tagText: string; optionRows: any[]; optionQuery: string}>(view);
    const writable = () => !props().locked && !props().field.readOnly;
    function commit(value: InputValue) { if (writable()) props().onChange(props().field.key, value); }
    function input(event: Event) { const element = event.target as HTMLInputElement; element.setCustomValidity(''); commit(element.value); }
    function check(event: Event) { commit((event.target as HTMLInputElement).checked); }
    function multiple(event: Event) { commit(Array.from((event.target as HTMLSelectElement).selectedOptions).map(option => option.value)); }
    function toggle(value: string, event: Event) {
        if (props().field.type === 'select') { commit(value); return; }
        const values = Array.isArray(props().field.value) ? [...props().field.value as string[]] : [];
        commit((event.target as HTMLInputElement).checked ? [...new Set([...values, value])] : values.filter(item => item !== value));
    }
    /** Matrix select-all (row, column, group): add or remove many cell values at once. */
    function toggleMany(values: string[], event: Event) {
        const current = Array.isArray(props().field.value) ? props().field.value as string[] : [];
        commit((event.target as HTMLInputElement).checked ? [...new Set([...current, ...values])] : current.filter(item => !values.includes(item)));
    }
    function tags(event: Event) {
        const value = (event.target as HTMLInputElement).value;
        state.set('tagText', value);
        commit([...new Set(value.split(',').map(item => item.trim()).filter(Boolean))]);
    }
    function filterOptions(event: Event) {
        state.set('optionQuery', (event.target as HTMLInputElement).value);
        updateOptions();
    }
    function updateOptions() {
        const query = state.get('optionQuery').toLocaleLowerCase();
        state.set('optionRows', props().field.options.filter(option => option.selected || (option.label || App.Helper.text(option.labelKey)).toLocaleLowerCase().includes(query)));
    }
    function format(command: string) {
        if (!writable()) return;
        const element = document.getElementById('resource-field-' + props().field.key) as HTMLTextAreaElement | null;
        if (!element) return;
        const html = props().field.type === 'richtext';
        const wrappers: Record<string, [string, string]> = html
            ? {bold: ['<strong>', '</strong>'], italic: ['<em>', '</em>'], heading: ['<h2>', '</h2>'], list: ['<ul><li>', '</li></ul>'], link: ['<a href="https://example.com">', '</a>'], code: ['<code>', '</code>']}
            : {bold: ['**', '**'], italic: ['_', '_'], heading: ['\n## ', '\n'], list: ['\n- ', '\n'], link: ['[', '](https://example.com)'], code: ['`', '`']};
        const pair = wrappers[command]; if (!pair) return;
        const start = element.selectionStart, end = element.selectionEnd;
        element.setRangeText(pair[0] + element.value.slice(start, end) + pair[1], start, end, 'select');
        commit(element.value); element.focus();
    }
    function formatJson() {
        if (!writable()) return;
        const element = document.getElementById('resource-field-' + props().field.key) as HTMLTextAreaElement | null;
        if (!element) return;
        try { const value = JSON.stringify(JSON.parse(element.value), null, 2); element.value = value; commit(value); element.setCustomValidity(''); }
        catch { element.setCustomValidity(App.Helper.text('admin.input.invalid_json')); element.reportValidity(); }
    }
    function clear() { commit(props().field.nullable ? null : ''); }
    function media() { if (writable() && props().mediaAvailable) props().onMedia(props().field.key); }
    const actions = {input, check, multiple, toggle, toggleMany, tags, filterOptions, format, formatJson, clear, media};
    view.__ctrl__.setUserDefinedConfig(actions);
    return actions;
}

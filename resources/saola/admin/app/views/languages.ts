import type {} from '../contracts/admin';
import type { Application, View } from '@saolabs/client';
import { bindViewState } from './binding';
import {noticeKind} from '../feedback';
export interface LanguagesProps {
    editor: any;
}
export interface LanguagesState {
    localeDoc: any;
    permissions: any;
    batches: any[];
    busy: boolean;
    dirty: boolean;
    canSave: boolean;
    canPublish: boolean;
    selectedLocale: string;
    baseline: string;
    fileName: string;
    notice: string;
    noticeKind: string;
    details: string;
    preview: any;
    changes: any[];
}
/** Shared actions and lifecycle; each binding owns its own draft and subscriptions. */
export function bindLanguages(view: View, App: Application, readProps: () => LanguagesProps) {
    const binding = bindViewState<LanguagesState>(view);
    let form: any;
    let active = false;
    let disposers: Array<() => void> = [];
    function refresh() {
        if (!active || !form)
            return;
        binding.set("localeDoc", JSON.parse(JSON.stringify(form.document)));
        binding.set("permissions", { ...form.editor.permissions });
        binding.set("batches", [...form.editor.batches]);
        binding.set("busy", form.busy);
        binding.set("dirty", form.dirty);
        binding.set("canSave", form.canSave);
        binding.set("canPublish", form.canPublish);
        binding.set("selectedLocale", form.selectedLocale);
        binding.set("baseline", form.baseline);
        binding.set("fileName", form.fileName);
        binding.set("notice", form.notice);
        binding.set('noticeKind', noticeKind(form.notice));
        binding.set("details", form.details);
        binding.set("preview", form.preview);
        binding.set("changes", form.changes);
    }
    function canLeave() {
        if (form.canLeave())
            return true;
        form.notice = 'admin.languages.unsaved';
        refresh();
        return false;
    }
    function beforeUnload(event: BeforeUnloadEvent) {
        if (!form.canLeave()) {
            event.preventDefault();
            event.returnValue = '';
        }
    }
    function activate() {
        if (active)
            return;
        active = true;
        const ui = App.Admin;
        if (!form)
            form = ui.createLanguageForm(readProps().editor);
        disposers.push(ui.registerLanguageGuard(canLeave));
        disposers.push((App.Router as any).addNavigationGuard(canLeave));
        window.addEventListener('beforeunload', beforeUnload);
        App.Head?.setTitle(App.Helper.text('admin.languages.title'));
        refresh();
    }
    function deactivate() {
        active = false;
        disposers.splice(0).forEach(dispose => dispose());
        window.removeEventListener('beforeunload', beforeUnload);
        form?.suspend();
    }
    function started() {
        activate();
    }
    function resumed() {
        activate();
    }
    function paused() {
        deactivate();
    }
    function stopped() {
        deactivate();
    }
    function destroyed() {
        deactivate();
    }
    function changeDefault(key: string, event: Event) {
        form.changeDefault(key, (event.target as HTMLSelectElement).value);
        refresh();
    }
    function changeName(code: string, event: Event) {
        form.changeRow(code, 'name', (event.target as HTMLInputElement).value);
        refresh();
    }
    function changeText(code: string, key: string, event: Event) {
        form.changeRow(code, key, (event.target as HTMLInputElement).value);
        refresh();
    }
    function changeFlag(code: string, key: string, event: Event) {
        form.changeRow(code, key, (event.target as HTMLInputElement).checked);
        refresh();
    }
    function add(event: SubmitEvent) {
        const input = event.currentTarget as HTMLFormElement;
        const data = new FormData(input);
        if (form.add(String(data.get('code') || ''), String(data.get('name') || ''), String(data.get('country') || ''), String(data.get('currency') || '')))
            input.reset();
        refresh();
    }
    function selectLocale(event: Event) {
        const input = event.target as HTMLSelectElement;
        if (!form.selectLocale(input.value))
            input.value = form.selectedLocale;
        refresh();
    }
    function changeBaseline(event: Event) {
        form.setBaseline((event.target as HTMLInputElement).value);
        refresh();
    }
    async function loadFile(event: Event) {
        const file = (event.target as HTMLInputElement).files?.[0];
        if (!file)
            return;
        const pending = form.loadFile(file);
        refresh();
        await pending;
        refresh();
    }
    async function run(pending: Promise<void>) {
        refresh();
        await pending;
        refresh();
    }
    async function save() {
        await run(form.save());
    }
    async function reload() {
        await run(form.reload());
    }
    function discard() {
        form.discard();
        const input = document.getElementById('language-file') as HTMLInputElement;
        if (input)
            input.value = '';
        refresh();
    }
    async function previewJson() {
        await run(form.previewJson());
    }
    async function apply(publish: boolean) {
        await run(form.apply(publish));
    }
    async function openBatch(batch: string) {
        await run(form.openBatch(batch));
    }
    function useBaseline(code: string, batch: string) {
        if (form.selectLocale(code))
            form.setBaseline(batch);
        refresh();
    }
    async function exportJson() {
        await run(form.exportJson());
        if (!active || !form.download)
            return;
        const url = URL.createObjectURL(new Blob([form.download.json], { type: 'application/json' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = form.download.filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        form.download = null;
    }
    const actions = { started, resumed, paused, stopped, destroyed, changeDefault, changeName, changeText, changeFlag, add, selectLocale, changeBaseline, loadFile, save, reload, discard, previewJson, apply, openBatch, useBaseline, exportJson };
    view.__ctrl__.setUserDefinedConfig(actions);
    return actions;
}

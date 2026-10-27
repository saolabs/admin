import type {} from '../contracts/admin';
import type { Application, View } from '@saolabs/client';
import { bindViewState } from './binding';
import {noticeKind} from '../feedback';
export interface SettingsProps {
    editor: any;
}
export interface SettingsState {
    fields: any[];
    busy: boolean;
    dirty: boolean;
    canSave: boolean;
    canUpdate: boolean;
    notice: string;
    errors: any;
    mediaOpen: boolean;
    noticeKind: string;
    fieldEpoch: number;
}
/** Shared actions and lifecycle; each binding owns its own draft and subscriptions. */
export function bindSettings(view: View, App: Application, readProps: () => SettingsProps) {
    const binding = bindViewState<SettingsState>(view);
    let form: any;
    let formIdentity = '';
    let mediaField = '';
    function openMedia(key: string) {
        if (!form.canUpdate || form.busy)
            return;
        mediaField = key;
        binding.set("mediaOpen", true);
    }
    function closeMedia() {
        binding.set("mediaOpen", false);
        mediaField = '';
    }
    function selectMedia(item: any) {
        if (!mediaField || item.kind !== 'image')
            return;
        form.change(mediaField, item.url);
        refresh();
        closeMedia();
    }
    function clearMedia(key: string) {
        form.change(key, '');
        refresh();
    }
    let disposers: Array<() => void> = [];
    let active = false;
    const richBody = (key: string) => document.querySelector<HTMLElement>('[data-rich-body="' + key + '"]');
    /** The editable region mirrors the form value (first paint, discard, reload, and re-renders that clear it). While typing the two already agree. */
    function syncRich() {
        if (!active || !form)
            return;
        for (const field of form.fields as any[]) {
            const body = field.type === 'richtext' ? richBody(field.key) : null;
            if (body && body.innerHTML !== field.value && !(field.value === '' && body.textContent!.trim() === ''))
                body.innerHTML = field.value;
        }
    }
    function refresh() {
        if (!active || !form)
            return;
        requestAnimationFrame(syncRich);
        setTimeout(syncRich, 60);
        binding.set("fields", form.fields.map((field: any) => ({ ...field })));
        binding.set("busy", form.busy);
        binding.set("dirty", form.dirty);
        binding.set("canSave", form.canSave);
        binding.set("canUpdate", form.canUpdate);
        binding.set("errors", { ...form.errors });
        binding.set("notice", form.notice);
        binding.set('noticeKind', noticeKind(form.notice));
    }
    function canLeave() {
        if (form.canLeave())
            return true;
        form.notice = 'admin.settings.unsaved';
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
        const identity = readProps().editor['group'] + ':' + readProps().editor['contentLocale'];
        if (!form || formIdentity !== identity) {
            if (form) binding.set('fieldEpoch', binding.get('fieldEpoch') + 1);
            form = ui.createSettingsForm(readProps().editor);
            formIdentity = identity;
        }
        disposers.push(ui.registerLanguageGuard(canLeave));
        const router = App.Router as any;
        disposers.push(router.addNavigationGuard(canLeave));
        window.addEventListener('beforeunload', beforeUnload);
        if (typeof readProps().editor['titleKey'] === 'string')
            App.Head?.setTitle(App.Helper.text(readProps().editor['titleKey']));
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
    function changeText(key: string, event: Event) {
        form.change(key, (event.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement).value);
        refresh();
    }
    function changeNumber(key: string, event: Event) {
        const input = event.target as HTMLInputElement;
        form.change(key, Number.isFinite(input.valueAsNumber) ? input.valueAsNumber : input.value);
        refresh();
    }
    function changeRich(key: string, event: Event) {
        const html = (event.target as HTMLElement).innerHTML;
        const empty = (event.target as HTMLElement).textContent!.trim() === '' && !/<(ul|ol|li)\b/i.test(html);
        form.change(key, empty ? '' : html);
        refresh();
    }
    function richCommand(key: string, command: string) {
        richBody(key)?.focus();
        document.execCommand(command);
        richBody(key)?.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function richLink(key: string) {
        const url = window.prompt(App.Helper.text('admin.settings.rich.link_prompt'), 'https://');
        if (!url)
            return;
        richBody(key)?.focus();
        document.execCommand('createLink', false, url.trim());
        richBody(key)?.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function pastePlain(event: ClipboardEvent) {
        event.preventDefault();
        document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') ?? '');
    }
    function changeCheckbox(key: string, event: Event) {
        form.change(key, (event.target as HTMLInputElement).checked);
        refresh();
    }
    function discard() {
        form.discard();
        binding.set('fieldEpoch', binding.get('fieldEpoch') + 1);
        refresh();
    }
    async function save() {
        const pending = form.save();
        refresh();
        await pending;
        refresh();
        if (active && Object.keys(form.errors).length > 0) {
            const field = form.fields.find((field: any) => form.errors[field.key]);
            requestAnimationFrame(() => requestAnimationFrame(() => {
                if (active) (field ? document.getElementById('setting-' + field.key) : document.getElementById('settings-notice'))?.focus();
            }));
        }
    }
    async function reload() {
        const pending = form.reload();
        refresh();
        await pending;
        if (active && form.notice === 'admin.settings.reloaded') binding.set('fieldEpoch', binding.get('fieldEpoch') + 1);
        refresh();
    }
    function changeContentLocale(event: Event) {
        const input = event.target as HTMLSelectElement;
        if (!canLeave()) {
            input.value = readProps().editor['contentLocale'];
            return;
        }
        const target = new URL(window.location.href);
        target.searchParams.set('content_locale', input.value);
        (App.Router as any).replace(target.pathname + target.search);
    }
    const actions = { openMedia, closeMedia, selectMedia, clearMedia, started, resumed, paused, stopped, destroyed, changeText, changeNumber, changeRich, richCommand, richLink, pastePlain, changeCheckbox, discard, save, reload, changeContentLocale };
    view.__ctrl__.setUserDefinedConfig(actions);
    return actions;
}

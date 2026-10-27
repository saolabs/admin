import type {} from '../contracts/admin';
import type { Application, View } from '@saolabs/client';
import { bindViewState } from './binding';
import {noticeKind} from '../feedback';
export interface MediaLibraryProps {
    editor: any;
    picker: boolean;
    imageOnly: boolean;
    onSelect: any;
    onClose: any;
}
export interface MediaLibraryState {
    items: any[];
    selected: any;
    title: string;
    alt: string;
    caption: string;
    busy: boolean;
    dirty: boolean;
    canSave: boolean;
    notice: string;
    noticeKind: string;
    progress: string;
    total: number;
    page: number;
    query: string;
    kind: string;
    status: string;
    dragging: boolean;
}
/** Shared actions and lifecycle; each binding owns its own draft and subscriptions. */
export function bindMediaLibrary(view: View, App: Application, readProps: () => MediaLibraryProps) {
    const binding = bindViewState<MediaLibraryState>(view);
    let form: any;
    let active = false;
    let disposers: Array<() => void> = [];
    let previousFocus: HTMLElement | null = null;
    function refresh() {
        if (!active || !form)
            return;
        binding.set("items", form.items.map((item: any) => ({ ...item })));
        binding.set("selected", form.selected ? { ...form.selected } : null);
        binding.set("title", form.title);
        binding.set("alt", form.alt);
        binding.set("caption", form.caption);
        binding.set("busy", form.busy);
        binding.set("dirty", form.dirty);
        binding.set("canSave", form.canSave);
        binding.set("notice", form.notice);
        binding.set('noticeKind', noticeKind(form.notice));
        binding.set("progress", form.progress);
        binding.set("total", form.total);
        binding.set("page", form.page);
        binding.set("kind", form.kind);
        binding.set("status", form.status);
    }
    function canLeave() {
        if (form.canLeave())
            return true;
        form.notice = 'admin.media.unsaved';
        refresh();
        return false;
    }
    function beforeUnload(event: BeforeUnloadEvent) {
        if (!form.canLeave()) {
            event.preventDefault();
            event.returnValue = '';
        }
    }
    function keydown(event: KeyboardEvent) {
        if (!readProps().picker)
            return;
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        }
        if (event.key === 'Tab') {
            const root = document.getElementById('media-library');
            const nodes = Array.from(root?.querySelectorAll<HTMLElement>('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [href]') || []);
            const first = nodes[0], last = nodes[nodes.length - 1];
            if (event.shiftKey && (document.activeElement === first || document.activeElement === root)) {
                event.preventDefault();
                last?.focus();
            }
            else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        }
    }
    async function run(pending: Promise<any>) {
        refresh();
        const result = await pending;
        refresh();
        return result;
    }
    function activate() {
        if (active)
            return;
        active = true;
        const ui = App.Admin;
        form ||= ui.createMediaLibrary(readProps().editor, readProps().imageOnly);
        disposers.push(ui.registerLanguageGuard(canLeave));
        disposers.push((App.Router as any).addNavigationGuard(canLeave));
        window.addEventListener('beforeunload', beforeUnload);
        document.addEventListener('keydown', keydown);
        if (readProps().picker) {
            previousFocus = document.activeElement as HTMLElement;
            document.getElementById('media-library')?.focus();
        }
        else App.Head?.setTitle(App.Helper.text('admin.media.title'));
        run(form.load());
    }
    function deactivate() {
        if (!active)
            return;
        active = false;
        form?.suspend();
        disposers.splice(0).forEach(dispose => dispose());
        window.removeEventListener('beforeunload', beforeUnload);
        document.removeEventListener('keydown', keydown);
        if (readProps().picker)
            previousFocus?.focus();
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
    function updateQuery(event: Event) {
        binding.set("query", (event.target as HTMLInputElement).value);
    }
    function search() {
        run(form.load({ q: binding.get("query"), page: 1 }));
    }
    function changeKind(event: Event) {
        const input = event.target as HTMLSelectElement;
        if (!canLeave()) {
            input.value = binding.get("kind");
            return;
        }
        run(form.load({ q: binding.get("query"), kind: input.value }));
    }
    function changeStatus(event: Event) {
        const input = event.target as HTMLSelectElement;
        if (!canLeave()) {
            input.value = binding.get("status");
            return;
        }
        run(form.load({ q: binding.get("query"), status: input.value }));
    }
    function changePage(delta: number) {
        run(form.load({ page: binding.get("page") + delta }));
    }
    function selectItem(item: any) {
        form.select(item);
        refresh();
    }
    function editTitle(event: Event) {
        form.edit('title', (event.target as HTMLInputElement).value);
        refresh();
    }
    function editAlt(event: Event) {
        form.edit('alt', (event.target as HTMLInputElement).value);
        refresh();
    }
    function editCaption(event: Event) {
        form.edit('caption', (event.target as HTMLTextAreaElement).value);
        refresh();
    }
    function discard() {
        form.discard();
        refresh();
    }
    function save() {
        run(form.save());
    }
    function move() {
        run(form.move(binding.get("selected")['trashed']));
    }
    function upload(event: Event) {
        const input = event.target as HTMLInputElement;
        run(form.upload(Array.from(input.files || [])));
        input.value = '';
    }
    function dragOver() {
        if (readProps().editor['permissions']['upload'] && !binding.get('dragging'))
            binding.set('dragging', true);
    }
    function dragLeave(event: DragEvent) {
        const next = event.relatedTarget as Node | null;
        if (!next || !(event.currentTarget as HTMLElement).contains(next))
            binding.set('dragging', false);
    }
    function dropFiles(event: DragEvent) {
        binding.set('dragging', false);
        if (readProps().editor['permissions']['upload'])
            run(form.upload(Array.from(event.dataTransfer?.files || [])));
    }
    function close() {
        if (canLeave() && typeof readProps().onClose === 'function')
            readProps().onClose();
    }
    async function choose() {
        const item = await run(form.choose());
        if (item && active && typeof readProps().onSelect === 'function')
            readProps().onSelect(item);
    }
    const actions = { started, resumed, paused, stopped, destroyed, updateQuery, search, changeKind, changeStatus, changePage, selectItem, editTitle, editAlt, editCaption, discard, save, move, upload, dragOver, dragLeave, dropFiles, close, choose };
    view.__ctrl__.setUserDefinedConfig(actions);
    return actions;
}

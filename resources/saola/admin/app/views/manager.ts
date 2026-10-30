import {noticeFeedback} from '../feedback';
import type {} from '../contracts/admin';
import type { Application, View } from '@saolabs/client';
import { bindViewState } from './binding';
import type {InputValue} from '../resource-manager';
export interface ManagerProps {
    resource: any;
}
export interface ManagerState {
    data: any;
    fields: any[];
    rows: any[];
    fieldEpoch: number;
    busy: boolean;
    dirty: boolean;
    canSave: boolean;
    errors: any;
    selectedCount: number;
    allSelected: boolean;
    canTrash: boolean;
    canRestore: boolean;
    locked: boolean;
    canSelect: boolean;
    partiallySelected: boolean;
    filtersOpen: boolean;
    sections: any[];
    activeSection: string;
    mediaOpen: boolean;
    imageOnly: boolean;
}
/** Shared actions and lifecycle; each binding owns its own draft and subscriptions. */
export function bindManager(view: View, App: Application, readProps: () => ManagerProps) {
    const binding = bindViewState<ManagerState>(view);
    const feedback = noticeFeedback(App);
    let manager: any;
    let identity = '';
    let active = false;
    let disposers: Array<() => void> = [];
    let mediaField = '';
    function refresh() {
        if (!active || !manager)
            return;
        binding.set("data", { ...manager.data });
        binding.set("fields", manager.fields.map((field: any) => ({ ...field })));
        const sections = (manager.data.sections || [{key: 'main', labelKey: ''}]).map((section: any) => ({...section,
            fields: manager.fields.filter((field: any) => (field.section || 'main') === section.key)})).filter((section: any) => section.fields.length > 0);
        binding.set('sections', sections);
        if (!sections.some((section: any) => section.key === binding.get('activeSection'))) binding.set('activeSection', sections[0]?.key || 'main');
        binding.set("rows", manager.rows);
        binding.set("busy", manager.busy);
        binding.set("locked", manager.locked);
        binding.set("dirty", manager.dirty);
        binding.set("canSave", manager.canSave);
        feedback(manager.notice, manager.noticeKind, Object.values(manager.errors).join('\n'));
        binding.set("errors", { ...manager.errors });
        binding.set("selectedCount", manager.selectedCount);
        binding.set("canTrash", manager.canBulk('trash'));
        binding.set("canRestore", manager.canBulk('restore'));
        const selectable = manager.rows.filter((row: any) => row.selectable);
        binding.set("canSelect", selectable.length > 0);
        binding.set("allSelected", selectable.length > 0 && selectable.every((row: any) => row.selected));
        binding.set("partiallySelected", manager.selectedCount > 0 && manager.selectedCount < selectable.length);
    }
    function canLeave() {
        feedback('');
        if (manager.canLeave())
            return true;
        manager.notice = 'admin.resource.unsaved';
        refresh();
        return false;
    }
    function beforeUnload(event: BeforeUnloadEvent) {
        if (!manager.canLeave()) {
            event.preventDefault();
            event.returnValue = '';
        }
    }
    function activate() {
        if (active)
            return;
        active = true;
        binding.set('mediaOpen', false); mediaField = '';
        const ui = App.Admin;
        const next = readProps().resource['key'] + ':' + readProps().resource['mode'] + ':' + readProps().resource['endpoint'] + ':' + (readProps().resource['listUrl'] ?? '');
        const resume = !!manager && identity === next;
        if (!resume || (manager.completed && readProps().resource['mode'] === 'create')) {
            const resetControls = !!manager;
            manager?.destroy();
            manager = ui.createResourceManager(readProps().resource);
            identity = next;
            if (resetControls)
                binding.set("fieldEpoch", binding.get("fieldEpoch") + 1);
        }
        disposers.push(ui.registerLanguageGuard(canLeave));
        disposers.push((App.Router as any).addNavigationGuard(canLeave));
        window.addEventListener('beforeunload', beforeUnload);
        const mode = readProps().resource['mode'] === 'create' ? 'admin.resource.create' : (readProps().resource['canSave'] ? 'admin.resource.edit' : 'admin.resource.view');
        App.Head?.setTitle((readProps().resource['mode'] === 'list' ? '' : App.Helper.text(mode) + ' · ') + App.Helper.text(readProps().resource['titleKey']));
        refresh();
        if (resume && readProps().resource['mode'] !== 'create' && manager.canLeave())
            void reload();
    }
    function deactivate() {
        active = false;
        disposers.splice(0).forEach(dispose => dispose());
        window.removeEventListener('beforeunload', beforeUnload);
        manager?.suspend();
        mediaField = '';
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
        manager?.destroy();
    }
    function change(key: string, event: Event) {
        manager.change(key, (event.target as HTMLInputElement).value);
        refresh();
    }
    function changeCheck(key: string, event: Event) {
        manager.change(key, (event.target as HTMLInputElement).checked);
        refresh();
    }
    function changeValue(key: string, value: InputValue) { manager.change(key, value); refresh(); }
    function selectSection(key: string) { binding.set('activeSection', key); }
    function sectionKeydown(event: KeyboardEvent) {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        const sections = binding.get('sections'); if (sections.length < 2) return;
        const current = sections.findIndex(section => section.key === binding.get('activeSection'));
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? sections.length - 1
            : (current + (event.key === 'ArrowRight' ? 1 : -1) + sections.length) % sections.length;
        event.preventDefault(); selectSection(sections[next].key); document.getElementById('resource-tab-' + sections[next].key)?.focus();
    }
    function openMedia(key: string) {
        const field = manager.fields.find((field: any) => field.key === key);
        if (!field || manager.locked || field.readOnly || !manager.data.canSave || !manager.data.mediaEditor) return;
        mediaField = key; binding.set('imageOnly', field.type === 'image'); binding.set('mediaOpen', true);
    }
    function closeMedia() { binding.set('mediaOpen', false); mediaField = ''; }
    function selectMedia(item: any) {
        if (!mediaField || item.trashed || (binding.get('imageOnly') && item.kind !== 'image')) return;
        changeValue(mediaField, item.url); closeMedia();
    }
    function selectRow(id: string, event: Event) {
        manager.select(id, (event.target as HTMLInputElement).checked);
        refresh();
    }
    function selectAll(event: Event) {
        manager.selectAll((event.target as HTMLInputElement).checked);
        refresh();
    }
    function toggleFilters() {
        binding.set("filtersOpen", !binding.get("filtersOpen"));
    }
    function clearSelection() {
        manager.selectAll(false);
        refresh();
    }
    function discard() {
        manager.discard();
        binding.set("fieldEpoch", binding.get("fieldEpoch") + 1);
        refresh();
        closeMedia();
    }
    function cancel() {
        discard();
        if (manager.canLeave())
            void App.Router.navigate(manager.listUrl);
    }
    async function save() {
        const pending = manager.save();
        refresh();
        await pending;
        refresh();
        if (active && manager.errors && Object.keys(manager.errors).length > 0) {
            const key = Object.keys(manager.errors).find((key: string) => manager.fields.some((field: any) => field.key === key));
            const field = manager.fields.find((field: any) => field.key === key);
            if (field) binding.set('activeSection', field.section || 'main');
            requestAnimationFrame(() => requestAnimationFrame(() => {
                if (active) (key ? document.getElementById('resource-field-' + key) : null)?.focus();
            }));
        }
        if (active && manager.redirect)
            void App.Router.navigate(manager.redirect);
    }
    async function reload() {
        const pending = manager.reload();
        refresh();
        await pending;
        if (active && manager.notice === '' && !manager.dirty)
            binding.set("fieldEpoch", binding.get("fieldEpoch") + 1);
        refresh();
    }
    async function transition(operation: string, id?: string) {
        if (!window.confirm(App.Helper.text('admin.resource.confirm_' + operation)))
            return;
        const pending = manager.transition(operation, id);
        refresh();
        await pending;
        refresh();
    }
    function filter(event: SubmitEvent) {
        const params = new URLSearchParams();
        for (const [key, value] of new FormData(event.currentTarget as HTMLFormElement))
            if (typeof value === 'string')
                params.set(key, value);
        void App.Router.navigate(binding.get("data")['listing']['action'] + '?' + params.toString());
    }
    const actions = { started, resumed, paused, stopped, destroyed, change, changeCheck, changeValue, selectSection, sectionKeydown, openMedia, closeMedia, selectMedia, selectRow, selectAll, toggleFilters, clearSelection, discard, cancel, save, reload, transition, filter };
    view.__ctrl__.setUserDefinedConfig(actions);
    return actions;
}

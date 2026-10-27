import type {Application, View} from '@saolabs/client';
import type {UiControlData} from '../contracts/ui';
import {bindViewState} from './binding';

type Kind = 'alert' | 'toast' | 'dropdown' | 'tabs' | 'accordion' | 'dialog';
interface State {open: boolean; active: string; expanded: string[]; visible: boolean;}
// Document effects must be coordinated across simultaneous dialogs, even from different Apps.
const scrollLocks = new WeakMap<Document, {owners: Set<symbol>; previous: string}>();

/** No Bootstrap/global template JS: each view owns its interaction state and listeners. */
export function bindUi(view: View, _app: Application, readData: () => Partial<UiControlData>, kind: Kind) {
    const state = bindViewState<State>(view);
    const owner = Symbol('ui-dialog');
    let listening = false;
    let trigger: HTMLElement | null = null;
    let focusTimer: ReturnType<typeof setTimeout> | undefined;
    let focusFrame: number | undefined;
    let dismissTimer: ReturnType<typeof setTimeout> | undefined;
    const root = () => document.getElementById(readData().id ?? '');
    const panel = () => root()?.querySelector<HTMLElement>('[role="dialog"]');
    function lock() {
        let entry = scrollLocks.get(document);
        if (!entry) { entry = {owners: new Set(), previous: document.body.style.overflow}; scrollLocks.set(document, entry); }
        entry.owners.add(owner); document.body.style.overflow = 'hidden';
    }
    function unlock() {
        const entry = scrollLocks.get(document);
        if (!entry || !entry.owners.delete(owner)) return;
        if (!entry.owners.size) { document.body.style.overflow = entry.previous; scrollLocks.delete(document); }
    }
    function laterFocus(selector?: string) {
        clearTimeout(focusTimer);
        focusTimer = setTimeout(() => {
            if (!listening || !state.get('open')) return;
            focusFrame = requestAnimationFrame(() => {
                if (!listening || !state.get('open')) return;
                const target = selector ? root()?.querySelector<HTMLElement>(selector) : panel();
                target?.focus();
            });
        }, 0);
    }
    function close(restoreFocus = true) {
        if (view.emit('beforeclose') === false) return;
        state.set('open', false); unlock(); clearTimeout(focusTimer);
        if (focusFrame !== undefined) cancelAnimationFrame(focusFrame);
        if (restoreFocus && trigger?.isConnected) trigger.focus();
        view.emit('close');
    }
    function toggle(event: Event) {
        if (state.get('open')) { close(); return; }
        trigger = root()?.querySelector<HTMLElement>('[data-ui-trigger]') ?? event.currentTarget as HTMLElement;
        state.set('open', true);
        if (kind === 'dialog') { lock(); laterFocus(); }
    }
    function dismiss() { state.set('visible', false); clearTimeout(dismissTimer); view.emit('close'); }
    function backdrop(event: Event) {
        if (readData().dismissible !== false && event.target === event.currentTarget) close();
    }
    function choose(id: string) {
        const item = readData().items?.find(item => item.id === id);
        if (!item || item.disabled) return;
        if (kind === 'tabs') { state.set('active', id); view.emit('change', id); }
        else { close(); view.emit('action', id); }
    }
    function expand(id: string) {
        const item = readData().items?.find(item => item.id === id);
        if (!item || item.disabled) return;
        const current = state.get('expanded');
        const next = current.includes(id) ? current.filter(key => key !== id) : readData().multiple ? [...current, id] : [id];
        state.set('expanded', next); view.emit('change', next);
    }
    function keydown(event: KeyboardEvent) {
        if (event.key === 'Escape' && state.get('open')) { event.preventDefault(); close(); return; }
        if (kind === 'dialog' && state.get('open') && event.key === 'Tab') {
            const nodes = Array.from(panel()?.querySelectorAll<HTMLElement>('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]') || []);
            if (!nodes.length) { event.preventDefault(); panel()?.focus(); return; }
            const first = nodes[0], last = nodes[nodes.length - 1];
            if (event.shiftKey && (document.activeElement === first || document.activeElement === panel())) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && (document.activeElement === last || document.activeElement === panel())) { event.preventDefault(); first.focus(); }
        }
        const keys = kind === 'tabs' ? ['ArrowRight', 'ArrowLeft', 'Home', 'End'] : ['ArrowDown', 'ArrowUp', 'Home', 'End'];
        if (!['tabs', 'dropdown'].includes(kind) || !keys.includes(event.key)) return;
        event.preventDefault();
        if (kind === 'dropdown' && !state.get('open')) { toggle(event); laterFocus('[role="menuitem"]:not(:disabled)'); return; }
        const nodes = Array.from(root()?.querySelectorAll<HTMLElement>(kind === 'tabs' ? '[role="tab"]:not(:disabled)' : '[role="menuitem"]:not(:disabled)') || []);
        if (!nodes.length) return;
        const current = nodes.indexOf(document.activeElement as HTMLElement);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? nodes.length - 1 : ['ArrowRight', 'ArrowDown'].includes(event.key) ? (current + 1) % nodes.length : (current - 1 + nodes.length) % nodes.length;
        nodes[next].focus(); if (kind === 'tabs') nodes[next].click();
    }
    function outside(event: Event) {
        if (kind === 'dropdown' && state.get('open') && !root()?.contains(event.target as Node)) close(false);
    }
    function start() {
        if (listening) return;
        listening = true;
        if (kind === 'dropdown') { document.addEventListener('pointerdown', outside); document.addEventListener('focusin', outside); }
        if (kind === 'dialog' && state.get('open')) { trigger = document.activeElement as HTMLElement; lock(); laterFocus(); }
        const timeout = readData().timeout;
        if (kind === 'toast' && state.get('visible') && typeof timeout === 'number' && timeout > 0) dismissTimer = setTimeout(dismiss, timeout);
    }
    function release() {
        listening = false; clearTimeout(focusTimer); clearTimeout(dismissTimer);
        if (focusFrame !== undefined) cancelAnimationFrame(focusFrame);
        document.removeEventListener('pointerdown', outside); document.removeEventListener('focusin', outside);
        unlock();
        if (['dropdown', 'dialog'].includes(kind)) state.set('open', false);
    }
    const actions = {toggle, close, dismiss, backdrop, choose, expand, keydown, started: start, resumed: start, paused: release, stopped: release, destroyed: release};
    view.__ctrl__.setUserDefinedConfig(actions);
    return actions;
}

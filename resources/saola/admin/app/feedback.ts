export type NoticeKind = 'success' | 'info' | 'warning' | 'error';

/** Keep feedback semantics consistent across modules and themes. */
export function noticeKind(key: string): NoticeKind {
    const name = key.split('.').pop() || '';
    if (['saved', 'published', 'draft_saved', 'uploaded', 'trashed', 'restored', 'changed'].includes(name)) return 'success';
    if (['reloaded', 'preview_ready', 'exported', 'save_new'].includes(name)) return 'info';
    if (['unsaved', 'reload_required', 'changed_reload', 'session_expired', 'conflict', 'forbidden', 'unavailable'].includes(name)) return 'warning';
    return 'error';
}

/** Floating, theme-independent feedback owned by one admin Application. */
export class AdminToasts {
    private stack?: HTMLElement;
    private dismissers = new Map<HTMLElement, () => void>();

    show(message: string, kind: NoticeKind, closeLabel: string): () => void {
        if (!message.trim()) return () => {};
        for (const [item, dismiss] of [...this.dismissers]) {
            if (item.dataset.kind === kind && item.querySelector('.sa-admin-notification-text')?.textContent === message) dismiss();
        }
        if (!this.stack?.isConnected) {
            this.clear();
            this.stack = document.createElement('div');
            this.stack.className = 'sa-admin-toast-stack';
            document.body.append(this.stack);
        }
        const item = document.createElement('div');
        item.className = 'sa-admin-notification';
        item.dataset.kind = kind;
        item.setAttribute('role', kind === 'error' || kind === 'warning' ? 'alert' : 'status');
        item.setAttribute('aria-atomic', 'true');
        const text = document.createElement('div');
        text.className = 'sa-admin-notification-text';
        text.textContent = message;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'sa-admin-notification-close';
        close.setAttribute('aria-label', closeLabel);
        close.textContent = '×';
        let timer: ReturnType<typeof setTimeout> | undefined;
        const dismiss = () => {
            clearTimeout(timer);
            this.dismissers.delete(item);
            item.remove();
            if (!this.dismissers.size) { this.stack?.remove(); this.stack = undefined; }
        };
        const schedule = () => {
            clearTimeout(timer);
            // Problems stay visible until dismissed; ordinary results expire.
            if (kind === 'success' || kind === 'info') timer = setTimeout(dismiss, 6000);
        };
        close.addEventListener('click', dismiss);
        item.addEventListener('mouseenter', () => clearTimeout(timer));
        item.addEventListener('focusin', () => clearTimeout(timer));
        item.addEventListener('mouseleave', schedule);
        item.addEventListener('focusout', event => {
            if (!item.contains(event.relatedTarget as Node | null)) schedule();
        });
        item.append(text, close);
        this.stack.append(item);
        this.dismissers.set(item, dismiss);
        schedule();
        return dismiss;
    }

    clear(): void {
        for (const dismiss of [...this.dismissers.values()]) dismiss();
        this.stack?.remove(); this.stack = undefined;
    }
}

/** A notice can survive many reactive refreshes; announce each result once. */
export function noticeFeedback(app: import('@saolabs/client').Application) {
    let previous = '';
    return (key: string, kind = noticeKind(key), details = '') => {
        const identity = key ? key + '\n' + details : '';
        if (identity === previous) return;
        previous = identity;
        if (key) app.Admin.notify([app.Helper.text(key), details].filter(Boolean).join('\n'), kind);
    };
}

const reloadNoticeKey = 'saola.admin.reload-notice';

export function rememberNotice(message: string, kind: NoticeKind): void {
    try { sessionStorage.setItem(reloadNoticeKey, JSON.stringify({message, kind, expires: Date.now() + 30000})); } catch { /* Storage can be unavailable. */ }
}

export function restoreNotice(notify: (message: string, kind: NoticeKind) => unknown): void {
    try {
        const raw = sessionStorage.getItem(reloadNoticeKey);
        sessionStorage.removeItem(reloadNoticeKey);
        if (!raw) return;
        const notice = JSON.parse(raw);
        if (typeof notice.message === 'string' && notice.expires > Date.now() && ['success', 'info', 'warning', 'error'].includes(notice.kind)) notify(notice.message, notice.kind);
    } catch { /* A stale or unavailable storage entry must not break the panel. */ }
}

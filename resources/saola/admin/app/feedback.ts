export type NoticeKind = 'success' | 'info' | 'warning' | 'error';

/** Keep feedback semantics consistent across modules and themes. */
export function noticeKind(key: string): NoticeKind {
    const name = key.split('.').pop() || '';
    if (['saved', 'published', 'draft_saved', 'uploaded', 'trashed', 'restored', 'changed'].includes(name)) return 'success';
    if (['reloaded', 'preview_ready', 'exported', 'save_new'].includes(name)) return 'info';
    if (['unsaved', 'reload_required', 'changed_reload', 'session_expired', 'conflict', 'forbidden', 'unavailable'].includes(name)) return 'warning';
    return 'error';
}

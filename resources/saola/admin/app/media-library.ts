export type MediaItem = {id: string; title: string; alt: string; caption: string; filename: string; url: string;
    kind: 'image' | 'video' | 'audio' | 'document'; mime: string; size: number; width: number | null; height: number | null; revision: number; trashed: boolean; createdAt: string};
export type MediaList = {items: MediaItem[]; total: number; page: number; perPage: number};
export type MediaEditor = {endpoint: string; csrfToken: string; maxBytes: number; accept: string;
    permissions: Record<'view' | 'upload' | 'update' | 'delete', boolean>; initial?: MediaList};

/** A page or picker owns its own requests, selection and metadata draft. */
export class MediaLibraryForm {
    items: MediaItem[] = [];
    total = 0; page = 1; perPage = 24;
    q = ''; kind = 'all'; status = 'active';
    selected: MediaItem | null = null;
    title = ''; alt = ''; caption = '';
    busy = false; notice = ''; progress = '';
    private generation = 0;
    private pending?: AbortController;
    private blocked = false;

    constructor(public editor: MediaEditor, private imageOnly = false) {
        if (imageOnly) this.kind = 'image';
        if (editor.initial && !imageOnly) this.install(editor.initial);
    }
    get dirty(): boolean { return !!this.selected && (this.title !== this.selected.title || this.alt !== this.selected.alt || this.caption !== this.selected.caption); }
    get canSave(): boolean { return this.dirty && !this.busy && !this.blocked && !!this.editor.permissions.update && !this.selected?.trashed; }
    canLeave(): boolean { return !this.busy && !this.dirty; }
    select(item: MediaItem): void {
        if (!this.canLeave()) { this.notice = 'admin.media.unsaved'; return; }
        this.selected = {...item}; this.title = item.title; this.alt = item.alt; this.caption = item.caption; this.notice = ''; this.blocked = false;
    }
    edit(key: 'title' | 'alt' | 'caption', value: string): void {
        if (this.busy || !this.editor.permissions.update || !this.selected || this.selected.trashed) return;
        this[key] = value;
    }
    discard(): void {
        if (this.busy) return;
        this.title = this.selected?.title || ''; this.alt = this.selected?.alt || ''; this.caption = this.selected?.caption || ''; this.notice = '';
    }
    suspend(): void { if (this.busy) this.blocked = true; ++this.generation; this.pending?.abort(); this.pending = undefined; this.busy = false; }
    async load(filters: {q?: string; kind?: string; status?: string; page?: number} = {}, fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.canLeave()) { this.notice = 'admin.media.unsaved'; return; }
        const q = filters.q ?? this.q; const kind = this.imageOnly ? 'image' : filters.kind ?? this.kind;
        const status = filters.status ?? this.status; const page = filters.page ?? 1;
        const params = new URLSearchParams({q, kind, status, page: String(page)});
        const result = await this.request('GET', '?' + params, undefined, fetcher);
        if (result) {
            try { this.install(result.data); this.q = q; this.kind = kind; this.status = status; this.selected = null; this.blocked = false; }
            catch { this.notice = 'admin.media.failed'; }
        }
    }
    async upload(files: File[], fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.editor.permissions.upload || !this.canLeave() || this.blocked) return;
        if (!files.length || files.length > 20 || files.some(file => file.size === 0 || file.size > this.editor.maxBytes || this.imageOnly && !file.type.startsWith('image/'))) { this.notice = 'admin.media.invalid_file'; return; }
        this.status = 'active';
        let uploaded: MediaItem | null = null;
        for (let index = 0; index < files.length; index++) {
            this.progress = `${index + 1} / ${files.length}`;
            const data = new FormData(); data.append('file', files[index]);
            const result = await this.request('POST', '', data, fetcher);
            if (!result) { this.progress = ''; return; }
            if (!this.validItem(result.item)) { this.blocked = true; this.notice = 'admin.media.failed'; this.progress = ''; return; }
            uploaded = result.item;
        }
        this.progress = ''; await this.load({q: '', status: 'active', page: 1}, fetcher);
        if (uploaded && !this.notice) { this.select(uploaded); this.notice = 'admin.media.uploaded'; }
    }
    async save(fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.canSave || !this.selected) return;
        const item = this.selected;
        const result = await this.request('PUT', '/' + item.id, {revision: item.revision, changes: {title: this.title, alt: this.alt, caption: this.caption}}, fetcher);
        if (result && this.validItem(result.item) && result.item.id === item.id && result.item.revision === item.revision + 1) {
            this.selected = result.item; this.items = this.items.map(row => row.id === item.id ? result.item : row); this.notice = 'admin.media.saved';
        } else if (result) { this.blocked = true; this.notice = 'admin.media.failed'; }
    }
    async move(restore: boolean, fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.selected || !this.editor.permissions.delete || !this.canLeave() || this.blocked) return;
        const item = this.selected;
        const result = await this.request('POST', '/' + item.id + (restore ? '/restore' : '/trash'), {revision: item.revision}, fetcher);
        if (result && this.validItem(result.item) && result.item.id === item.id && result.item.revision === item.revision + 1 && result.item.trashed === !restore) {
            await this.load({}, fetcher); if (!this.notice) this.notice = restore ? 'admin.media.restored' : 'admin.media.trashed';
        } else if (result) { this.blocked = true; this.notice = 'admin.media.failed'; }
    }
    async choose(fetcher: typeof fetch = fetch): Promise<MediaItem | null> {
        if (!this.selected || !this.canLeave() || this.selected.trashed || this.blocked) return null;
        const item = this.selected;
        const result = await this.request('GET', '/' + item.id, undefined, fetcher);
        if (!result || !this.validItem(result.item) || result.item.id !== item.id || result.item.trashed || this.imageOnly && result.item.kind !== 'image') {
            this.notice ||= 'admin.media.unavailable'; return null;
        }
        return result.item;
    }
    private install(list: MediaList): void {
        if (!list || !Array.isArray(list.items) || !list.items.every(item => this.validItem(item)) || !Number.isSafeInteger(list.total) || list.total < 0
            || !Number.isSafeInteger(list.page) || list.page < 1 || list.perPage !== 24) throw new Error('Invalid media list');
        this.items = list.items.map(item => ({...item})); this.total = list.total; this.page = list.page; this.perPage = list.perPage;
    }
    private validItem(item: any): item is MediaItem {
        return !!item && /^[0-9a-f-]{36}$/i.test(item.id) && ['image', 'video', 'audio', 'document'].includes(item.kind)
            && ['title', 'alt', 'caption', 'filename', 'url', 'mime'].every(key => typeof item[key] === 'string')
            && /^\/(?!\/)/.test(item.url) && !/[\x00-\x20\\]/.test(item.url)
            && Number.isSafeInteger(item.revision) && item.revision > 0 && Number.isSafeInteger(item.size) && item.size > 0 && typeof item.trashed === 'boolean';
    }
    private async request(method: string, path: string, body: unknown, fetcher: typeof fetch): Promise<any | null> {
        const generation = ++this.generation; const pending = new AbortController(); this.pending = pending;
        this.busy = true; this.notice = '';
        try {
            const multipart = body instanceof FormData;
            const response = await fetcher(this.editor.endpoint + path, {method, credentials: 'same-origin', signal: pending.signal,
                headers: {Accept: 'application/json', ...(body === undefined ? {} : {'X-CSRF-TOKEN': this.editor.csrfToken}), ...(body !== undefined && !multipart ? {'Content-Type': 'application/json'} : {})},
                ...(body === undefined ? {} : {body: multipart ? body : JSON.stringify(body)})});
            if (generation !== this.generation) return null;
            if ([401, 403, 419].includes(response.status)) { this.blocked = true; this.notice = 'admin.media.forbidden'; return null; }
            if (response.status === 409) { this.blocked = true; this.notice = 'admin.media.conflict'; return null; }
            if ([413, 422].includes(response.status)) { this.notice = 'admin.media.invalid_file'; return null; }
            if (!response.ok) throw new Error('Media request failed');
            const result = await response.json(); return generation === this.generation ? result : null;
        } catch { if (generation === this.generation) { if (method !== 'GET') this.blocked = true; this.notice = 'admin.media.failed'; } return null; }
        finally { if (generation === this.generation) { this.busy = false; this.pending = undefined; } }
    }
}

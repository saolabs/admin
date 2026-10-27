export type LocaleRow = {code: string; name: string; country: string; currency: string; revision: number; uiEnabled: boolean; contentEnabled: boolean};
export type LocaleDocument = {revision: number; uiDefault: string; uiFallback: string; contentDefault: string; locales: LocaleRow[]};
export type Reference = {countries: Array<{code: string; name: string}>; currencies: Array<{code: string; name: string}>};
export type LanguageEditor = {document: LocaleDocument; reference?: Reference; permissions: Record<'view' | 'manage' | 'export' | 'import' | 'publish', boolean>;
    endpoint: string; csrfToken: string; maxBytes: number; batches: Array<{batch: string; locale: string; kind: string; status: string}>};
type Preview = {batch: string; locale: string; status: string; changes: Record<string, string>; untranslated: string[]};
const copy = <T>(value: T): T => JSON.parse(JSON.stringify(value));

/** Per-view draft and request lifecycle. Global defaults and dictionaries remain server-owned. */
export class LanguageForm {
    document: LocaleDocument;
    selectedLocale: string;
    baseline = '';
    fileJson = '';
    fileName = '';
    preview: Preview | null = null;
    notice = '';
    details = '';
    busy = false;
    download: {filename: string; json: string} | null = null;
    private base: LocaleDocument;
    private fileDirty = false;
    private generation = 0;
    private pending?: AbortController;
    private requiresReload = false;

    constructor(public editor: LanguageEditor) {
        this.validate(editor);
        this.base = copy(editor.document); this.document = copy(this.base);
        this.selectedLocale = this.document.locales[0]?.code || '';
    }
    get configDirty(): boolean { return JSON.stringify(this.document) !== JSON.stringify(this.base); }
    get dirty(): boolean { return this.configDirty || this.fileDirty; }
    canLeave(): boolean { return !this.dirty && !this.busy; }
    get canSave(): boolean { return this.editor.permissions.manage && this.configDirty && !this.fileDirty && !this.busy && !this.requiresReload; }
    get changes(): Array<{key: string; value: string}> { return Object.entries(this.preview?.changes || {}).slice(0, 100).map(([key, value]) => ({key, value})); }
    get canPublish(): boolean { return this.editor.permissions.publish && this.editor.permissions.import && !!this.preview && this.preview.status !== 'published' && !this.dirty && !this.busy && !this.requiresReload; }

    changeDefault(key: 'uiDefault' | 'uiFallback' | 'contentDefault', value: string): void {
        if (!this.editor.permissions.manage || this.busy) return;
        this.document = {...this.document, [key]: value}; this.notice = ''; this.details = '';
    }
    changeRow(code: string, key: 'name' | 'country' | 'currency' | 'uiEnabled' | 'contentEnabled', value: string | boolean): void {
        if (!this.editor.permissions.manage || this.busy) return;
        if (key === 'country' || key === 'currency') value = String(value).trim().toUpperCase().slice(0, key === 'country' ? 2 : 3);
        this.document = {...this.document, locales: this.document.locales.map(row => row.code === code ? {...row, [key]: value} : row)};
        this.notice = ''; this.details = '';
    }
    add(code: string, name: string, country = '', currency = ''): boolean {
        if (!this.editor.permissions.manage || this.busy) return false;
        code = code.trim(); name = name.trim(); country = country.trim().toUpperCase(); currency = currency.trim().toUpperCase();
        if (!/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/.test(code) || code.length > 40 || !name || name.length > 100 || !/^([A-Z]{2})?$/.test(country) || !/^([A-Z]{3})?$/.test(currency)
            || this.document.locales.some(row => row.code.replace('_', '-').toLowerCase() === code.replace('_', '-').toLowerCase())) {
            this.notice = 'admin.languages.invalid_locale'; return false;
        }
        this.document = {...this.document, locales: [...this.document.locales, {code, name, country, currency, revision: 0, uiEnabled: true, contentEnabled: true}]};
        this.notice = 'admin.languages.save_new'; return true;
    }
    selectLocale(code: string): boolean {
        if (!this.canLeave()) { this.notice = 'admin.languages.unsaved'; return false; }
        this.selectedLocale = code; this.preview = null; this.baseline = ''; this.fileJson = ''; this.fileName = ''; this.details = ''; return true;
    }
    setBaseline(value: string): void {
        if (this.busy) return;
        this.baseline = value; this.preview = null;
        if (this.fileJson) this.fileDirty = true;
    }
    async loadFile(file: File): Promise<void> {
        if (this.busy || !this.editor.permissions.import) return;
        if (this.configDirty) { this.notice = 'admin.languages.unsaved'; return; }
        if (file.size > this.editor.maxBytes) { this.notice = 'admin.languages.file_large'; return; }
        const generation = ++this.generation; this.busy = true;
        try {
            const json = await file.text();
            if (generation !== this.generation) return;
            if (new TextEncoder().encode(json).length > this.editor.maxBytes) { this.notice = 'admin.languages.file_large'; return; }
            this.fileJson = json; this.fileName = file.name; this.fileDirty = true; this.preview = null; this.notice = ''; this.details = '';
            const suffix = file.name.match(/\.([0-9a-f-]{36})\.json$/i);
            if (suffix) this.baseline = suffix[1];
        } catch { if (generation === this.generation) this.notice = 'admin.languages.failed'; }
        finally { if (generation === this.generation) this.busy = false; }
    }
    discard(): void {
        if (this.busy) return;
        ++this.generation;
        this.document = copy(this.base); this.fileJson = ''; this.fileName = ''; this.fileDirty = false; this.preview = null; this.details = '';
        this.notice = this.requiresReload ? 'admin.languages.reload_required' : '';
    }
    suspend(): void {
        ++this.generation;
        if (this.busy) { this.requiresReload = true; this.notice = 'admin.languages.reload_required'; }
        this.pending?.abort(); this.pending = undefined; this.busy = false;
    }
    async save(fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.canSave) return;
        const body = {...this.document, locales: this.document.locales.map(({code, name, country, currency, uiEnabled, contentEnabled}) => ({code, name, country, currency, uiEnabled, contentEnabled}))};
        await this.request('', 'PUT', body, 'save', fetcher);
    }
    async reload(fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.canLeave()) { this.notice = 'admin.languages.unsaved'; return; }
        await this.request('', 'GET', undefined, 'reload', fetcher);
    }
    async exportJson(fetcher: typeof fetch = fetch): Promise<void> {
        if (this.requiresReload) { this.notice = 'admin.languages.reload_required'; return; }
        if (!this.editor.permissions.export || !this.canLeave()) { this.notice = 'admin.languages.unsaved'; return; }
        await this.request('/' + encodeURIComponent(this.selectedLocale) + '/export', 'POST', {}, 'export', fetcher);
    }
    async previewJson(fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.editor.permissions.import || !this.fileJson || !this.baseline || this.busy || this.configDirty || this.requiresReload) return;
        await this.request('/' + encodeURIComponent(this.selectedLocale) + '/preview', 'POST', {json: this.fileJson, exportBatch: this.baseline}, 'preview', fetcher);
    }
    async apply(publish: boolean, fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.preview || !this.canLeave() || this.requiresReload || (publish && !this.editor.permissions.publish) || !this.editor.permissions.import) return;
        await this.request('/batches/' + this.preview.batch, 'POST', {publish}, publish ? 'publish' : 'draft', fetcher);
    }
    async openBatch(batch: string, fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.canLeave() || !this.editor.permissions.import) { this.notice = 'admin.languages.unsaved'; return; }
        await this.request('/batches/' + batch, 'GET', undefined, 'batch', fetcher);
    }
    private validate(editor: LanguageEditor): void {
        const doc = editor?.document;
        if (!doc || !Number.isSafeInteger(doc.revision) || doc.revision < 0 || !Array.isArray(doc.locales) || typeof editor.csrfToken !== 'string'
            || typeof editor.endpoint !== 'string' || !Number.isSafeInteger(editor.maxBytes) || !Array.isArray(editor.batches)
            || ['view', 'manage', 'export', 'import', 'publish'].some(key => typeof (editor.permissions as any)?.[key] !== 'boolean')
            || ['uiDefault', 'uiFallback', 'contentDefault'].some(key => typeof (doc as any)[key] !== 'string')
            || doc.locales.some(row => !row || typeof row.code !== 'string' || typeof row.name !== 'string' || typeof row.country !== 'string' || typeof row.currency !== 'string' || !Number.isSafeInteger(row.revision) || row.revision < 0
                || typeof row.uiEnabled !== 'boolean' || typeof row.contentEnabled !== 'boolean')) throw new Error('Invalid locale state');
    }
    private async request(path: string, method: string, body: unknown, operation: string, fetcher: typeof fetch): Promise<void> {
        this.busy = true; this.notice = ''; this.details = ''; this.download = null;
        const generation = ++this.generation; const pending = new AbortController(); this.pending = pending;
        try {
            const response = await fetcher(this.editor.endpoint + path, {method, credentials: 'same-origin', signal: pending.signal,
                headers: {Accept: 'application/json', ...(body === undefined ? {} : {'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.editor.csrfToken})},
                ...(body === undefined ? {} : {body: JSON.stringify(body)})});
            if (generation !== this.generation) return;
            if (response.status === 409 || response.status === 410) { this.requiresReload = true; this.notice = 'admin.languages.conflict'; return; }
            if ([401, 403, 419].includes(response.status)) { this.requiresReload = true; this.notice = 'admin.languages.forbidden'; return; }
            const result = await response.json(); if (generation !== this.generation) return;
            if (response.status === 422) {
                this.notice = 'admin.languages.invalid';
                this.details = Object.values(result.errors || {}).flat().filter(value => typeof value === 'string').join('\n'); return;
            }
            if (!response.ok) throw new Error('Language request failed');
            this.validate(result.editor);
            if (result.editor.endpoint !== this.editor.endpoint || (operation === 'save' && result.editor.document.revision !== this.base.revision + 1)) throw new Error('Unconfirmed locale state');
            let preview: Preview | null = null;
            if (['preview', 'batch', 'publish', 'draft'].includes(operation)) {
                preview = result.preview;
                if (!preview || typeof preview.batch !== 'string' || typeof preview.locale !== 'string' || !['preview', 'draft', 'published'].includes(preview.status)
                    || !preview.changes || typeof preview.changes !== 'object' || Object.values(preview.changes).some(value => typeof value !== 'string') || !Array.isArray(preview.untranslated)
                    || (operation !== 'batch' && preview.locale !== this.selectedLocale) || (operation === 'publish' && preview.status !== 'published')) throw new Error('Invalid language preview');
            }
            if (operation === 'export') {
                if (typeof result.export?.batch !== 'string' || !result.export.tree || typeof result.export.tree !== 'object') throw new Error('Invalid export');
                this.baseline = result.export.batch;
                this.download = {filename: `${this.selectedLocale}.${this.baseline}.json`, json: JSON.stringify(result.export.tree, null, 2)};
            }
            this.editor = result.editor; this.base = copy(this.editor.document); this.document = copy(this.base); this.requiresReload = false;
            if (preview) { this.preview = preview; this.selectedLocale = preview.locale; this.fileDirty = false; }
            if (operation === 'save' || operation === 'reload') { this.fileJson = ''; this.fileName = ''; this.fileDirty = false; this.preview = null; }
            this.notice = 'admin.languages.' + (operation === 'publish' ? 'published' : operation === 'draft' ? 'draft_saved' : operation === 'preview' || operation === 'batch' ? 'preview_ready' : operation === 'export' ? 'exported' : operation === 'reload' ? 'reloaded' : 'saved');
        } catch { if (generation === this.generation) this.notice = 'admin.languages.failed'; }
        finally { if (generation === this.generation) { this.busy = false; this.pending = undefined; } }
    }
}

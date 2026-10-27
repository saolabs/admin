import {safeUrl} from './url';
import {noticeKind} from './feedback';
export type InputValue = string | number | boolean | string[] | null;
export type ResourceField = {key: string; labelKey: string; type: string; template?: string; value: InputValue; readOnly: boolean;
    required: boolean; writeOnly: boolean; maxLength: number; nullable?: boolean; helpKey?: string; section?: string; span?: string;
    options: Array<{value: string; labelKey: string; label?: string; selected?: boolean}>; matrix?: MatrixData | null};
export type MatrixLabel = {labelKey: string; label?: string};
export type MatrixCell = {values: string[]; enabled: boolean; any: boolean; all: boolean; selected: boolean};
export type MatrixRow = MatrixLabel & {key: string; kind: 'group' | 'item'; depth: number; cells: MatrixCell[]; values: string[]; any: boolean; all: boolean};
export type MatrixHeader = MatrixLabel & {key: string; values: string[]; any: boolean; all: boolean};
export type MatrixData = {columns: Array<MatrixLabel & {key: string}>; headers: MatrixHeader[]; rows: MatrixRow[]; values: string[]; any: boolean; all: boolean};
/** Recompute selection flags and select-all aggregates from the field value (mirrors Matrix::project; the value lists are static). */
export function projectMatrix(matrix: MatrixData, value: InputValue): MatrixData {
    const on = new Set(Array.isArray(value) ? value : []);
    const all = (values: string[]) => values.length > 0 && values.every(item => on.has(item));
    return {...matrix, all: all(matrix.values), headers: matrix.headers.map(header => ({...header, all: all(header.values)})),
        rows: matrix.rows.map(row => ({...row, all: all(row.values), cells: row.cells.map(cell => ({...cell, all: all(cell.values), selected: all(cell.values)}))}))};
}
export type ResourceRow = {id: string; revision: string; values: Record<string, string>; actions: Array<{operation: string; labelKey: string; url: string}>; selectable: boolean; selected?: boolean};
export type Management = {schema: number; key: string; titleKey: string; mode: 'list' | 'create' | 'edit'; endpoint: string; returnUrl: string; csrfToken: string;
    fields: ResourceField[]; rows: ResourceRow[]; columns: Array<{key: string; labelKey: string; renderer?: string}>; canSave?: boolean; revision?: string;
    status?: string; listing?: any; counts?: {active: number; trash: number | null}; createUrl?: string; activeUrl?: string; trashUrl?: string; listUrl?: string; resetUrl?: string;
    formLayout?: 'sections' | 'tabs'; sections?: Array<{key: string; labelKey: string; descriptionKey?: string}>; mediaEditor?: any};

/** Each mounted manager owns its draft, selection and request receipt. Never keep these in AdminUI. */
export class ResourceManager {
    data: Management;
    fields: ResourceField[];
    busy = false;
    notice = '';
    errors: Record<string, string> = {};
    redirect = '';
    completed = false;
    private changes = new Map<string, ResourceField['value']>();
    private selected = new Set<string>();
    private generation = 0;
    private pending?: AbortController;
    private blocked = false;
    private receipt = crypto.randomUUID();

    constructor(data: Management) {
        this.data = structuredClone(data); this.fields = this.decorateFields(this.data.fields);
    }
    get dirty(): boolean { return this.changes.size > 0; }
    get canSave(): boolean { return !!this.data.canSave && !this.busy && !this.blocked && !this.completed && (this.data.mode === 'create' || this.dirty); }
    get locked(): boolean { return this.busy || this.blocked || this.completed; }
    get listUrl(): string { return this.data.listUrl || this.data.returnUrl; }
    get noticeKind(): string { return noticeKind(this.notice); }
    get rows(): ResourceRow[] { return this.data.rows.map(row => ({...row, selected: this.selected.has(row.id)})); }
    get selectedCount(): number { return this.selected.size; }
    canBulk(operation: string): boolean {
        const selected = this.data.rows.filter(row => this.selected.has(row.id));
        return !this.busy && !this.blocked && selected.length > 0 && selected.length <= 50 && selected.every(row => row.actions.some(action => action.operation === operation));
    }
    select(id: string, checked: boolean): void {
        if (this.locked || !this.data.rows.some(row => row.id === id && row.selectable)) return;
        if (checked) this.selected.add(id); else this.selected.delete(id);
    }
    selectAll(checked: boolean): void {
        if (this.locked) return;
        this.selected = new Set(checked ? this.data.rows.filter(row => row.selectable).map(row => row.id) : []);
    }
    change(key: string, value: ResourceField['value']): void {
        if (this.locked || !this.data.canSave) return;
        const original = this.data.fields.find(field => field.key === key);
        if (!original || original.readOnly) return;
        if (original.nullable && value === '') value = null;
        else if (original.type === 'integer' && typeof value === 'string' && /^-?\d+$/.test(value) && Number.isSafeInteger(Number(value))) value = Number(value);
        else if (original.type === 'number' && typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) value = Number(value);
        this.fields = this.decorateFields(this.fields.map(field => field.key === key ? {...field, value: structuredClone(value)} : field));
        const equal = Array.isArray(value) && Array.isArray(original.value)
            ? value.length === original.value.length && [...value].sort().every((item, index) => item === [...original.value as string[]].sort()[index])
            : Object.is(value, original.value) || (['integer', 'number'].includes(original.type) && value !== '' && value !== null && original.value !== '' && original.value !== null && Number(value) === Number(original.value));
        if (equal || (original.nullable && value === null && original.value === '')) this.changes.delete(key); else this.changes.set(key, structuredClone(value));
        delete this.errors[key]; this.notice = '';
    }
    discard(): void {
        if (this.busy) return;
        this.changes.clear(); this.fields = this.decorateFields(this.data.fields); this.errors = {}; this.notice = this.blocked ? 'admin.resource.reload_required' : '';
    }
    private decorateFields(fields: ResourceField[]): ResourceField[] {
        return structuredClone(fields).map(field => ({...field, tagText: field.type === 'tags' && Array.isArray(field.value) ? field.value.join(', ') : '', matrix: field.matrix ? projectMatrix(field.matrix, field.value) : null, previewUrl: field.type === 'image' ? safeUrl(field.value) : '', options: (field.options || []).map(option => ({...option,
            selected: Array.isArray(field.value) ? field.value.includes(option.value) : String(field.value ?? '') === option.value}))}));
    }
    canLeave(): boolean { return !this.dirty && !this.busy; }
    suspend(): void { ++this.generation; this.pending?.abort(); this.pending = undefined; this.busy = false; }
    destroy(): void {
        this.suspend(); this.changes.clear(); this.selected.clear();
        this.fields = this.fields.map(field => field.writeOnly ? {...field, value: ''} : field);
    }
    async save(fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.canSave) return;
        const values: Record<string, ResourceField['value']> = {};
        for (const field of this.fields) {
            if (field.readOnly || (field.writeOnly && (field.value === '' || field.value === null))) continue;
            if (this.data.mode === 'create' && !field.required && field.value === '' && !this.changes.has(field.key)) continue;
            if (this.data.mode === 'create' || this.changes.has(field.key)) values[field.key] = field.value;
        }
        const create = this.data.mode === 'create';
        await this.run(async (signal) => {
            const response = await fetcher(this.data.endpoint, this.options(create ? 'POST' : 'PUT', signal,
                create ? {values, requestId: this.receipt} : {values, revision: this.data.revision}));
            const body = await this.read(response);
            if (body.schema !== 1 || typeof body.id !== 'string' || body.redirect !== this.data.returnUrl) throw new Error('Unconfirmed Resource write.');
            return () => {
                this.changes.clear(); this.completed = true; this.redirect = this.listUrl;
                this.fields = this.fields.map(field => field.writeOnly ? {...field, value: ''} : field);
                this.notice = 'admin.resource.saved';
            };
        });
    }
    async reload(fetcher: typeof fetch = fetch, search = ''): Promise<void> {
        if (!this.canLeave()) { this.notice = 'admin.resource.unsaved'; return; }
        const query = search || (this.data.listUrl ? this.data.listUrl.slice(this.data.returnUrl.length) : this.data.mode === 'list' && typeof window !== 'undefined' ? window.location.search : '');
        await this.run(async signal => {
            const response = await fetcher(this.data.endpoint + query, this.options('GET', signal));
            const body = await this.read(response);
            this.validateResource(body.resource);
            return () => { this.data = structuredClone(body.resource); this.fields = this.decorateFields(this.data.fields); this.changes.clear(); this.selected.clear(); this.blocked = false; this.completed = false; };
        });
    }
    async transition(operation: string, id?: string, fetcher: typeof fetch = fetch): Promise<void> {
        if (this.busy || this.blocked) return;
        const rows = this.data.rows.filter(row => id ? row.id === id : this.selected.has(row.id));
        if (!rows.length || rows.length > 50 || !rows.every(row => row.actions.some(action => action.operation === operation))) return;
        const bulk = id === undefined;
        if (bulk && !['trash', 'restore'].includes(operation)) return;
        const endpoint = bulk ? this.data.endpoint + '/bulk' : this.data.endpoint + '/' + encodeURIComponent(id!) + '/' + operation;
        const payload = bulk ? {operation, items: rows.map(row => ({id: row.id, revision: row.revision}))} : {revision: rows[0].revision};
        await this.run(async signal => {
            const response = await fetcher(endpoint, this.options('POST', signal, payload));
            const body = await this.read(response);
            if (body.schema !== 1 || body.affected !== rows.length) throw new Error('Unconfirmed Resource transition.');
            // Do not optimistically remove records: refresh the filtered count, permissions and revisions together.
            let result: any;
            try {
                const query = this.data.listUrl ? this.data.listUrl.slice(this.data.returnUrl.length) : window.location.search;
                const list = await fetcher(this.data.endpoint + query, this.options('GET', signal));
                result = await this.read(list); this.validateResource(result.resource);
            } catch { throw {changed: true}; }
            return () => { this.data = structuredClone(result.resource); this.selected.clear(); this.notice = 'admin.resource.changed'; };
        });
    }
    private validateResource(data: any): void {
        if (!data || data.schema !== 1 || data.key !== this.data.key || data.mode !== this.data.mode || data.endpoint !== this.data.endpoint
            || data.returnUrl !== this.data.returnUrl || (data.listUrl && data.listUrl !== this.data.returnUrl && !data.listUrl.startsWith(this.data.returnUrl + '?'))
            || !Array.isArray(data.fields) || !Array.isArray(data.rows) || !Array.isArray(data.columns)) throw new Error('Invalid Resource response.');
    }
    private options(method: string, signal: AbortSignal, body?: any): RequestInit {
        return {method, signal, credentials: 'same-origin', headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.data.csrfToken},
            ...(body === undefined ? {} : {body: JSON.stringify(body)})};
    }
    private async read(response: Response): Promise<any> {
        // Auth/session errors may be HTML. Preserve their HTTP status instead of reporting a network failure.
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw {status: response.status, errors: body.errors};
        return body;
    }
    private async run(work: (signal: AbortSignal) => Promise<() => void>): Promise<void> {
        const generation = ++this.generation; const controller = new AbortController();
        this.pending = controller; this.busy = true; this.notice = ''; this.errors = {};
        try {
            const apply = await work(controller.signal);
            if (generation === this.generation) apply();
        } catch (error: any) {
            if (generation !== this.generation) return;
            const status = error?.status;
            if (error?.changed) { this.blocked = true; this.notice = 'admin.resource.changed_reload'; }
            else if (status === 422 && error.errors && typeof error.errors === 'object') {
                for (const [key, value] of Object.entries(error.errors)) {
                    const field = key.replace(/^values\./, '').split('.')[0];
                    this.errors[field] = [this.errors[field], Array.isArray(value) ? value.join(' ') : String(value)].filter(Boolean).join(' ');
                }
                this.notice = 'admin.resource.validation';
            } else if ([401, 419].includes(status)) {
                this.blocked = true; this.notice = 'admin.resource.session_expired';
            } else if ([403, 404, 409].includes(status)) {
                this.blocked = true; this.notice = status === 409 ? 'admin.resource.conflict' : 'admin.resource.forbidden';
            } else this.notice = 'admin.resource.failed';
        } finally { if (generation === this.generation) { this.busy = false; this.pending = undefined; } }
    }
}

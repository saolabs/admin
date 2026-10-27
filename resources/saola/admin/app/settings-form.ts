export type SettingValue = string | boolean | number;
export type Field = {key: string; labelKey: string; placeholderKey?: string; type: 'text' | 'textarea' | 'richtext' | 'url' | 'select' | 'integer' | 'boolean' | 'secret'; localized: boolean; media?: boolean; options?: Array<{value: string; label: string}>;
    value: SettingValue; present: boolean; maxLength: number; min: number | ''; max: number | ''};
export type SettingsDocument = {group: string; schemaVersion: number; revision: number; values: Record<string, SettingValue>;
    localized: Record<string, Record<string, SettingValue>>; contentLocales: string[]; defaultLocale: string};
export type Editor = {group: string; document: SettingsDocument; fields: Field[]; contentLocale: string; endpoint: string; csrfToken: string; canUpdate: boolean};

/** One view owns one instance. No actor, document or draft is kept in a singleton. */
export class SettingsForm {
    fields: Field[];
    busy = false;
    notice = '';
    errors: Record<string, string> = {};
    canUpdate: boolean;
    private changes = new Map<string, SettingValue>();
    private generation = 0;
    private pending?: AbortController;
    private requiresReload = false;

    constructor(private editor: Editor) {
        this.fields = editor.fields.map(field => ({...field}));
        this.canUpdate = editor.canUpdate;
    }

    get dirty(): boolean { return this.changes.size > 0; }
    get canSave(): boolean { return this.canUpdate && this.dirty && !this.busy && !this.requiresReload; }
    get revision(): number { return this.editor.document.revision; }

    change(key: string, value: SettingValue): void {
        if (!this.canUpdate || this.busy) return;
        const base = this.editor.fields.find(field => field.key === key);
        if (!base) return;
        this.fields = this.fields.map(field => field.key === key ? {...field, value} : field);
        // Interaction with a missing field explicitly creates an override, even
        // when the intended value is empty or happens to equal its fallback.
        if (base.present && Object.is(base.value, value)) this.changes.delete(key);
        else this.changes.set(key, value);
        delete this.errors[key]; this.notice = '';
    }

    discard(): void {
        if (this.busy) return;
        this.changes.clear(); this.fields = this.editor.fields.map(field => ({...field}));
        this.errors = {}; this.notice = this.requiresReload ? 'admin.settings.reload_required' : '';
    }

    canLeave(): boolean { return !this.dirty && !this.busy; }

    suspend(): void {
        ++this.generation;
        if (this.busy) { this.requiresReload = true; this.notice = 'admin.settings.reload_required'; }
        this.pending?.abort(); this.pending = undefined; this.busy = false;
    }

    async save(fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.canSave) return;
        const values: Record<string, SettingValue> = {}; const translated: Record<string, SettingValue> = {};
        for (const [key, value] of this.changes) {
            const field = this.fields.find(item => item.key === key)!;
            if (field.type === 'integer' && (typeof value !== 'number' || !Number.isSafeInteger(value))) {
                this.errors[key] = 'admin.settings.invalid_value';
            } else (field.localized ? translated : values)[key] = value;
        }
        if (Object.keys(this.errors).length) { this.notice = 'admin.settings.invalid'; return; }
        const patch: Record<string, any> = {};
        if (Object.keys(values).length) patch.values = values;
        if (Object.keys(translated).length) patch.localized = {[this.editor.contentLocale]: translated};
        await this.request('PUT', {revision: this.revision, patch}, fetcher);
    }

    async reload(fetcher: typeof fetch = fetch): Promise<void> {
        if (!this.canLeave()) { this.notice = 'admin.settings.unsaved'; return; }
        await this.request('GET', undefined, fetcher);
    }

    private async request(method: 'PUT' | 'GET', body: unknown, fetcher: typeof fetch): Promise<void> {
        this.busy = true; this.notice = ''; this.errors = {};
        const generation = ++this.generation;
        const pending = new AbortController(); this.pending = pending;
        try {
            const response = await fetcher(this.editor.endpoint, {method, credentials: 'same-origin', signal: pending.signal,
                headers: {Accept: 'application/json', ...(method === 'PUT' ? {'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.editor.csrfToken} : {})},
                ...(method === 'PUT' ? {body: JSON.stringify(body)} : {})});
            if (generation !== this.generation) return;
            if (response.status === 409) { this.requiresReload = true; this.notice = 'admin.settings.conflict'; return; }
            if (response.status === 401 || response.status === 403 || response.status === 419) {
                this.canUpdate = false; this.notice = 'admin.settings.forbidden'; return;
            }
            const result = await response.json();
            if (generation !== this.generation) return;
            if (response.status === 422) {
                for (const field of this.fields) {
                    const path = field.localized ? `localized.${this.editor.contentLocale}.${field.key}` : `values.${field.key}`;
                    if (result?.errors?.[path] || result?.errors?.[`patch.${path}`]) this.errors[field.key] = 'admin.settings.invalid_value';
                }
                this.notice = 'admin.settings.invalid'; return;
            }
            if (!response.ok) throw new Error('Settings request failed');
            if (method === 'PUT' && result?.data?.revision !== this.revision + 1) throw new Error('Unconfirmed settings revision');
            this.install(result);
            this.notice = method === 'PUT' ? 'admin.settings.saved' : 'admin.settings.reloaded';
        } catch {
            if (generation === this.generation) this.notice = 'admin.settings.failed';
        } finally {
            if (generation === this.generation) { this.busy = false; this.pending = undefined; }
        }
    }

    private install(result: any): void {
        const doc: SettingsDocument = result?.data;
        const isMap = (value: unknown): value is Record<string, any> => !!value && typeof value === 'object' && (!Array.isArray(value) || value.length === 0);
        const localizedGroup = this.editor.fields.some(field => field.localized);
        if (!doc || doc.group !== this.editor.group || !Number.isSafeInteger(doc.revision) || doc.revision < 0
            || doc.schemaVersion !== this.editor.document.schemaVersion || !isMap(doc.values) || !isMap(doc.localized) || !isMap(result?.schema?.fields)
            || !Array.isArray(doc.contentLocales) || (localizedGroup ? !doc.contentLocales.includes(this.editor.contentLocale) || !doc.contentLocales.includes(doc.defaultLocale) : doc.contentLocales.length !== 0 || doc.defaultLocale !== '')
            || typeof result.canUpdate !== 'boolean') throw new Error('Invalid settings response');
        const fields: Field[] = this.editor.fields.map(previous => {
            const schema = result.schema.fields[previous.key];
            if (!schema || schema.type !== previous.type || Boolean(schema.localized) !== previous.localized) throw new Error('Settings schema changed');
            const source = previous.localized ? (doc.localized[this.editor.contentLocale] ?? {}) : doc.values;
            const fallback = previous.localized ? (doc.localized[doc.defaultLocale]?.[previous.key] ?? schema.default) : schema.default;
            const value = source[previous.key] ?? fallback;
            if (!isMap(source) || (previous.type === 'boolean' ? typeof value !== 'boolean' : previous.type === 'integer' ? !Number.isSafeInteger(value) : typeof value !== 'string')) {
                throw new Error('Invalid settings value');
            }
            const options = previous.type === 'select' && isMap(schema.options) ? Object.entries(schema.options).map(([value, label]) => ({value, label: String(label)})) : previous.options;
            if (previous.type === 'select' && !options?.some(option => option.value === value)) throw new Error('Invalid select option');
            return {...previous, options, present: Object.prototype.hasOwnProperty.call(source, previous.key), value};
        });
        this.editor = {...this.editor, fields, document: doc}; this.fields = fields.map(field => ({...field}));
        this.canUpdate = result.canUpdate; this.changes.clear(); this.requiresReload = false;
    }
}

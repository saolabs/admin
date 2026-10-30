import {defineBundle, type Application, type View} from '@saolabs/client';
import {SettingsForm, type Editor} from './settings-form';
import {LanguageForm, type LanguageEditor} from './language-form';
import {MediaLibraryForm, type MediaEditor} from './media-library';
import {ResourceManager, type Management} from './resource-manager';
import type {AdminControllers} from './contracts/admin';
import {bindManager} from './views/manager';
import {bindShell} from './views/shell';
import {bindPage} from './views/page';
import {bindResource} from './views/resource';
import {bindSettings} from './views/settings-form';
import {bindLanguages} from './views/languages';
import {bindMediaLibrary} from './views/media-library';
import {bindInput} from './views/input';
import {bindUi} from './views/ui';
import {AdminToasts, restoreNotice, type NoticeKind} from './feedback';

/** Forms register a guard on mount and dispose it on pause/destroy. No model or auth state here. */
export class AdminUI {
    readonly controllers: AdminControllers;

    private toasts = new AdminToasts();

    notify(message: string, kind: NoticeKind = 'info'): () => void {
        return this.toasts.show(message, kind, this.app.Helper.text('admin.media.close'));
    }

    constructor(private app: Application) {
        restoreNotice((message, kind) => this.notify(message, kind));
        const bind = <F extends (view: View, app: Application, ...rest: any[]) => any>(fn: F) =>
            ((view: View, ...rest: any[]) => fn(view, app, ...rest));
        this.controllers = {
            manager: bind(bindManager), shell: bind(bindShell), page: bind(bindPage), resource: bind(bindResource),
            settings: bind(bindSettings), languages: bind(bindLanguages), mediaLibrary: bind(bindMediaLibrary), input: bind(bindInput),
            ui: bind(bindUi),
        } as AdminControllers;
    }

    createResourceManager(resource: Management): ResourceManager { return new ResourceManager(resource); }
    createSettingsForm(editor: Editor): SettingsForm { return new SettingsForm(editor); }

    createLanguageForm(editor: LanguageEditor): LanguageForm { return new LanguageForm(editor); }
    createMediaLibrary(editor: MediaEditor, imageOnly = false): MediaLibraryForm { return new MediaLibraryForm(editor, imageOnly); }

    clearNotifications(): void { this.toasts.clear(); }

    private guards = new Map<symbol, () => boolean>();
    private pageKey = '';
    private pageListeners = new Set<(key: string) => void>();

    setCurrentPage(key: string): void {
        if (!key || key === this.pageKey) return;
        this.pageKey = key;
        for (const listener of this.pageListeners) listener(key);
    }

    watchCurrentPage(listener: (key: string) => void): () => void {
        this.pageListeners.add(listener);
        if (this.pageKey) listener(this.pageKey);
        return () => { this.pageListeners.delete(listener); };
    }

    registerLanguageGuard(guard: () => boolean): () => void {
        const key = Symbol('admin-form');
        this.guards.set(key, guard);
        return () => { this.guards.delete(key); };
    }

    canChangeLanguage(): boolean { return [...this.guards.values()].every(guard => guard()); }
    reset(): void { this.guards.clear(); this.toasts.clear(); }
}

// Một instance MỖI Application (không phải mỗi module): `Admin` và `AdminUI` là
// cùng một service nên không có hai bản trạng thái. `AdminUI` là tên cũ, giữ để
// host/theme chưa đổi sang `App.Admin` vẫn chạy.
const instances = new WeakMap<object, AdminUI>();
const adminFor = (app: object): AdminUI => {
    let ui = instances.get(app);
    if (!ui) instances.set(app, ui = new AdminUI(app as Application));
    return ui;
};

export default defineBundle({name: 'saola/admin', services: {Admin: {factory: adminFor}, AdminUI: {factory: adminFor}}});

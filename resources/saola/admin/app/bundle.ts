import {defineBundle} from '@saolabs/client';
import '../assets/css/admin.css';
import {SettingsForm, type Editor} from './settings-form';

/** Forms register a guard on mount and dispose it on pause/destroy. No model or auth state here. */
export class AdminUI {
    createSettingsForm(editor: Editor): SettingsForm { return new SettingsForm(editor); }

    private guards = new Map<symbol, () => boolean>();

    registerLanguageGuard(guard: () => boolean): () => void {
        const key = Symbol('admin-form');
        this.guards.set(key, guard);
        return () => { this.guards.delete(key); };
    }

    canChangeLanguage(): boolean { return [...this.guards.values()].every(guard => guard()); }
    reset(): void { this.guards.clear(); }
}

export default defineBundle({name: 'saola/admin', services: {AdminUI: new AdminUI()}});

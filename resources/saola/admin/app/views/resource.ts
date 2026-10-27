import type { Application, View } from '@saolabs/client';
export interface ResourceProps {
    page: any;
}
/** Shared actions and lifecycle; each binding owns its own draft and subscriptions. */
export function bindResource(view: View, App: Application, readProps: () => ResourceProps) {
    function filter(event: SubmitEvent) {
        const form = event.currentTarget as HTMLFormElement;
        const params = new URLSearchParams();
        const data = new FormData(form);
        for (const key of ['locale', 'q', 'sort', 'direction', 'per_page']) {
            const value = data.get(key);
            if (typeof value === 'string')
                params.set(key, value);
        }
        void App.Router.navigate(readProps().page['listing']['action'] + '?' + params.toString());
    }
    function updateTitle() {
        if (typeof readProps().page['titleKey'] === 'string')
            App.Head?.setTitle(App.Helper.text(readProps().page['titleKey']));
    }
    function started() {
        updateTitle();
    }
    function resumed() {
        updateTitle();
    }
    const actions = { filter, started, resumed };
    view.__ctrl__.setUserDefinedConfig(actions);
    return actions;
}

import type {ShellPresentation} from '../../../app/contracts/admin';

/** Only Material's document styling belongs here; actions stay in the shared shell. */
export function createMaterialPresentation(): ShellPresentation {
    const attributes: Record<string, string> = {
        'data-layout': 'vertical', 'data-topbar': 'light',
        'data-sidebar': 'dark', 'data-bs-theme': 'light',
    };
    let previous: Record<string, string | null> | undefined;
    return {
        mobileBreakpoint: 768,
        expandedSubmenus: 'disclosure',
        activate() {
            previous ??= Object.fromEntries([...Object.keys(attributes), 'data-sidebar-size']
                .map(key => [key, document.documentElement.getAttribute(key)]));
        },
        update({compact}) {
            for (const [key, value] of Object.entries(attributes)) document.documentElement.setAttribute(key, value);
            document.documentElement.setAttribute('data-sidebar-size', compact ? 'sm' : 'lg');
        },
        deactivate() {
            if (!previous) return;
            for (const [key, value] of Object.entries(previous)) {
                if (value === null) document.documentElement.removeAttribute(key);
                else document.documentElement.setAttribute(key, value);
            }
            previous = undefined;
        },
    };
}

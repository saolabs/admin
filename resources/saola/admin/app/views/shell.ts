import type {} from '../contracts/admin';
import type { Application, View } from '@saolabs/client';
import { bindViewState } from './binding';
export interface ShellProps {
    navigation: any[];
    currentPage: string;
    brand: string;
    locales: any[];
    dictionaryPattern: string;
    uiLocale: string;
    account: any;
}
export interface ShellState {
    menuOpen: boolean;
    sidebarCompact: boolean;
    compactView: boolean;
    mobileView: boolean;
    flyoutKey: string;
    flyoutTop: number;
    notice: string;
    navItems: any[];
    languageOpen: boolean;
    accountOpen: boolean;
    selectedLocale: string;
    languageBusy: boolean;
}
export interface ShellPresentation {
    mobileBreakpoint?: number;
    expandedSubmenus?: 'hover' | 'disclosure';
    activate?(): void;
    update?(state: {
        compact: boolean;
        mobile: boolean;
    }): void;
    deactivate?(): void;
}
/** Shared actions and lifecycle; each binding owns its own draft and subscriptions. */
export function bindShell(view: View, App: Application, readProps: () => ShellProps, presentation: ShellPresentation = {}) {
    const binding = bindViewState<ShellState>(view);
    let stopPageWatch: (() => void) | undefined;
    let listening = false;
    let active = false;
    let languageGeneration = 0;
    let lastPage = '';
    let suppressFocus = false;
    function syncViewport() {
        const mobile = window.innerWidth < (presentation.mobileBreakpoint ?? 801);
        binding.set("mobileView", mobile);
        binding.set("compactView", !mobile && binding.get("sidebarCompact"));
        binding.set("flyoutKey", '');
        if (!mobile)
            binding.set("menuOpen", false);
        presentation.update?.({ compact: binding.get("compactView"), mobile });
    }
    function positionFlyout(event: Event) {
        const row = (event.currentTarget as HTMLElement).closest('.sa-admin-nav-group')?.querySelector<HTMLElement>('.sa-admin-nav-item');
        if (row)
            binding.set("flyoutTop", row.getBoundingClientRect().top);
    }
    function hoverSubmenu(key: string, event: Event) {
        if (binding.get("mobileView") || (!binding.get("compactView") && presentation.expandedSubmenus === 'disclosure'))
            return;
        positionFlyout(event);
        binding.set("flyoutKey", key);
    }
    function leaveSubmenu(key: string) {
        if (binding.get("flyoutKey") === key)
            binding.set("flyoutKey", '');
    }
    function focusSubmenu(key: string, event: Event) {
        if (!binding.get("mobileView") && !suppressFocus && (binding.get("compactView") || (presentation.expandedSubmenus !== 'disclosure' && !binding.get("navItems").find(item => item.key === key)?.active))) {
            positionFlyout(event);
            binding.set("flyoutKey", key);
        }
    }
    function blurSubmenu(key: string, event: FocusEvent) {
        if (!(event.currentTarget as HTMLElement).contains(event.relatedTarget as Node | null) && binding.get("flyoutKey") === key)
            binding.set("flyoutKey", '');
    }
    function closeFlyout() {
        binding.set("flyoutKey", '');
    }
    function updateNavigation(key: string) {
        const changed = lastPage !== key;
        lastPage = key;
        const previous = new Map(binding.get("navItems").map(item => [item.key, item.open]));
        binding.set("navItems", readProps().navigation.map(item => {
            const children = (item.children || []).map((child: any) => ({ ...child, active: child.key === key }));
            const active = item.key === key || children.some((child: any) => child.active);
            return { ...item, children, active, open: changed && active ? true : previous.get(item.key) ?? active };
        }));
    }
    function toggleSubmenu(key: string, event: Event) {
        if (binding.get("compactView") || (presentation.expandedSubmenus !== 'disclosure' && !binding.get("mobileView") && !binding.get("navItems").find(item => item.key === key)?.active)) {
            positionFlyout(event);
            binding.set("flyoutKey", key);
        }
        else {
            binding.set("flyoutKey", '');
            binding.set("navItems", binding.get("navItems").map(item => item.key === key ? { ...item, open: !item.open } : item));
        }
    }
    function watchPage() {
        if (active)
            return;
        presentation.activate?.();
        active = true;
        try {
            binding.set("sidebarCompact", window.localStorage.getItem('saola.admin.sidebar') === 'compact');
        }
        catch {
        }
        syncViewport();
        closeHeaderMenus();
        binding.set("languageBusy", false);
        binding.set("selectedLocale", App.Helper.getLocale());
        updateNavigation(readProps().currentPage);
        if (!stopPageWatch)
            stopPageWatch = App.Admin?.watchCurrentPage((key: string) => {
                updateNavigation(key);
                closeFlyout();
                closeHeaderMenus();
            });
        if (!listening) {
            document.addEventListener('pointerdown', outsideClick);
            document.addEventListener('focusin', outsideClick);
            document.addEventListener('keydown', headerKeydown);
            window.addEventListener('resize', syncViewport);
            listening = true;
        }
    }
    function releasePage() {
        active = false;
        ++languageGeneration;
        presentation.deactivate?.();
        stopPageWatch?.();
        stopPageWatch = undefined;
        document.removeEventListener('pointerdown', outsideClick);
        document.removeEventListener('focusin', outsideClick);
        document.removeEventListener('keydown', headerKeydown);
        window.removeEventListener('resize', syncViewport);
        listening = false;
    }
    function started() {
        watchPage();
    }
    function resumed() {
        watchPage();
    }
    function paused() {
        releasePage();
    }
    function stopped() {
        releasePage();
    }
    function destroyed() {
        releasePage();
    }
    function closeMenu() {
        binding.set("menuOpen", false);
        closeFlyout();
    }
    function toggleMenu() {
        closeHeaderMenus();
        closeFlyout();
        if (binding.get("mobileView")) {
            binding.set("menuOpen", !binding.get("menuOpen"));
            return;
        }
        const compact = !binding.get("sidebarCompact");
        binding.set("sidebarCompact", compact);
        binding.set("compactView", compact);
        presentation.update?.({ compact, mobile: binding.get("mobileView") });
        try {
            window.localStorage.setItem('saola.admin.sidebar', compact ? 'compact' : 'expanded');
        }
        catch {
        }
    }
    function closeHeaderMenus() {
        binding.set("languageOpen", false);
        binding.set("accountOpen", false);
    }
    function toggleLanguage() {
        binding.set("menuOpen", false);
        binding.set("accountOpen", false);
        binding.set("languageOpen", !binding.get("languageOpen"));
    }
    function toggleAccount() {
        binding.set("menuOpen", false);
        binding.set("languageOpen", false);
        binding.set("accountOpen", !binding.get("accountOpen"));
    }
    function outsideClick(event: Event) {
        if (!(event.target as Element)?.closest?.('.sa-admin-header-actions'))
            closeHeaderMenus();
        if (!(event.target as Element)?.closest?.('.sa-admin-sidebar'))
            closeFlyout();
    }
    function headerKeydown(event: KeyboardEvent) {
        if (event.key === 'Escape' && (binding.get("flyoutKey") || binding.get("menuOpen"))) {
            event.preventDefault();
            const key = binding.get("flyoutKey");
            closeMenu();
            const trigger = key ? document.querySelector<HTMLElement>(`button[aria-controls="admin-submenu-${key}"]`) : document.getElementById('admin-menu-toggle');
            suppressFocus = true;
            trigger?.focus();
            suppressFocus = false;
            return;
        }
        if (!binding.get("languageOpen") && !binding.get("accountOpen"))
            return;
        const id = binding.get("languageOpen") ? 'admin-language-toggle' : 'admin-account-toggle';
        if (event.key === 'Escape') {
            closeHeaderMenus();
            document.getElementById(id)?.focus();
        }
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            const popover = document.getElementById(binding.get("languageOpen") ? 'admin-language-menu' : 'admin-account-menu');
            const nodes = Array.from(popover?.querySelectorAll<HTMLElement>('button:not(:disabled), a[href]') || []);
            if (!nodes.length)
                return;
            const current = nodes.indexOf(document.activeElement as HTMLElement);
            const index = event.key === 'Home' ? 0 : event.key === 'End' ? nodes.length - 1 : event.key === 'ArrowDown' ? (current + 1) % nodes.length : (current < 0 ? nodes.length - 1 : current - 1 + nodes.length) % nodes.length;
            event.preventDefault();
            nodes[index]?.focus();
        }
    }
    function checkLogout(event: Event) {
        if (!(App.Admin?.canChangeLanguage() ?? true)) {
            event.preventDefault();
            closeHeaderMenus();
            binding.set("notice", 'admin.shell.unsaved');
        }
    }
    async function changeLanguage(locale: string) {
        if (binding.get("languageBusy"))
            return;
        if (locale === App.Helper.getLocale()) {
            closeHeaderMenus();
            return;
        }
        const generation = ++languageGeneration;
        binding.set("languageBusy", true);
        binding.set("notice", '');
        try {
            const changed = await App.Helper.changeLanguage(locale, async (code: string) => {
                const response = await fetch(readProps().dictionaryPattern.replace('_locale_', encodeURIComponent(code)), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!response.ok)
                    throw new Error('Dictionary request failed');
                return response.json();
            }, () => App.Admin?.canChangeLanguage() ?? true);
            if (active && generation === languageGeneration) {
                if (!changed)
                    binding.set("notice", 'admin.shell.unsaved');
                else
                    binding.set("selectedLocale", App.Helper.getLocale());
            }
        }
        catch {
            if (active && generation === languageGeneration)
                binding.set("notice", 'admin.shell.language_error');
        }
        finally {
            if (active && generation === languageGeneration) {
                binding.set("languageBusy", false);
                closeHeaderMenus();
            }
        }
    }
    const actions = { hoverSubmenu, leaveSubmenu, focusSubmenu, blurSubmenu, closeFlyout, toggleSubmenu, started, resumed, paused, stopped, destroyed, closeMenu, toggleMenu, closeHeaderMenus, toggleLanguage, toggleAccount, checkLogout, changeLanguage };
    view.__ctrl__.setUserDefinedConfig(actions);
    return actions;
}

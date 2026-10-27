/** Velzon Material data contracts. Text is escaped; actions use Saola view events. */
export type UiColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'danger' | 'dark' | 'light';
export interface UiButton {
    label: string; action?: string; href?: string; icon?: string; iconOnly?: boolean;
    color?: UiColor | 'link'; variant?: 'solid' | 'outline' | 'soft' | 'ghost';
    size?: 'sm' | 'md' | 'lg'; rounded?: boolean; disabled?: boolean; loading?: boolean;
    type?: 'button' | 'submit' | 'reset';
}
export interface UiItem { id: string; label: string; text?: string; icon?: string; disabled?: boolean; }
export interface UiControlData {
    /** Unique within the document, required for interactive groups. */
    id: string; label?: string; items?: UiItem[]; open?: boolean; active?: string;
    expanded?: string[]; multiple?: boolean;
    timeout?: number; dismissible?: boolean;
}
export interface UiCard {
    title?: string; subtitle?: string; text?: string; header?: string;
    image?: string; imageAlt?: string; imagePosition?: 'top' | 'middle' | 'bottom';
    items?: {id: string; label: string}[]; actions?: UiButton[]; actionsInBody?: boolean;
    color?: UiColor;
}
export interface UiStatCard {
    label: string; value: string | number; icon?: string; color?: UiColor; filled?: boolean;
    change?: string; trend?: 'up' | 'down' | 'neutral'; caption?: string; link?: UiButton;
}
export interface UiPagination {
    label: string; previousLabel: string; nextLabel: string; page: number; totalPages: number;
    pages?: number[]; size?: 'sm' | 'md';
}
export interface UiBadge {label?: string; color?: UiColor; icon?: string; soft?: boolean; rounded?: boolean;}
export interface UiAvatar {label?: string; src?: string; initials?: string; icon?: string; color?: UiColor; size?: 'xs' | 'sm' | 'md' | 'lg'; rounded?: boolean;}
export interface UiProgress {label?: string; value?: number; color?: UiColor; size?: 'sm' | 'md' | 'lg'; striped?: boolean; animated?: boolean; showValue?: boolean;}
export interface UiSpinner {label?: string; color?: UiColor; grow?: boolean; small?: boolean;}
export interface UiPlaceholder {label?: string; widths?: number[]; size?: 'sm' | 'md' | 'lg'; wave?: boolean;}
export interface UiEmptyState {title?: string; text?: string; icon?: string; action?: UiButton;}
export interface UiBreadcrumb {label?: string; items?: {id: string; label: string; href?: string; active?: boolean}[];}
export interface UiListGroup {flush?: boolean; numbered?: boolean; items?: (UiItem & {active?: boolean; badge?: string})[];}
export interface UiAlert extends UiControlData {title?: string; text?: string; color?: UiColor; icon?: string; borderLeft?: boolean; closeLabel?: string;}
export interface UiTabs extends UiControlData {active: string; items: UiItem[]; variant?: 'tabs' | 'pills' | 'line';}
export interface UiAccordion extends UiControlData {items: UiItem[]; flush?: boolean;}
export interface UiDropdown extends UiControlData {label: string; items: UiItem[]; color?: UiColor; align?: 'start' | 'end';}
export interface UiDialog extends UiControlData {
    title?: string; text?: string; closeLabel: string; actions?: UiButton[]; color?: UiColor;
    variant?: 'modal' | 'drawer'; placement?: 'start' | 'end' | 'top' | 'bottom';
    size?: 'sm' | 'md' | 'lg' | 'xl'; centered?: boolean; scrollable?: boolean;
}
export interface UiToast extends UiControlData {title?: string; text?: string; caption?: string; closeLabel: string; color?: UiColor;}
export interface UiPropsMap {
    button: UiButton; card: UiCard; 'stat-card': UiStatCard; badge: UiBadge; avatar: UiAvatar;
    progress: UiProgress; spinner: UiSpinner; placeholder: UiPlaceholder; 'empty-state': UiEmptyState;
    breadcrumb: UiBreadcrumb; 'list-group': UiListGroup; pagination: UiPagination; alert: UiAlert;
    tabs: UiTabs; accordion: UiAccordion; dropdown: UiDropdown; dialog: UiDialog; toast: UiToast;
}

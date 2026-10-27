import type {UiButton, UiPropsMap} from '../../resources/saola/admin/app/contracts/ui';
const save: UiButton = {label: 'Save', color: 'success', variant: 'outline', size: 'sm'};
const dialog: UiPropsMap['dialog'] = {id: 'settings-dialog', closeLabel: 'Close', variant: 'drawer', placement: 'end'};
// @ts-expect-error unsupported variants must not silently create a different design
const badVariant: UiButton = {label: 'Save', variant: 'neon'};
// @ts-expect-error template colors are a finite set
const badColor: UiButton = {label: 'Save', color: 'rainbow'};
void [save, dialog, badVariant, badColor];

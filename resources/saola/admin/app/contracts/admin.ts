import type {ResourceManager, Management} from '../resource-manager';
import type {SettingsForm, Editor} from '../settings-form';
import type {LanguageForm, LanguageEditor} from '../language-form';
import type {MediaLibraryForm, MediaEditor} from '../media-library';
import type {bindManager} from '../views/manager';
import type {bindShell} from '../views/shell';
import type {bindPage} from '../views/page';
import type {bindResource} from '../views/resource';
import type {bindSettings} from '../views/settings-form';
import type {bindLanguages} from '../views/languages';
import type {bindMediaLibrary} from '../views/media-library';
import type {bindInput} from '../views/input';
import type {bindUi} from '../views/ui';

export type {ShellPresentation, ShellProps} from '../views/shell';

/** `bindX(view, App, ...rest)` nội bộ → `controllers.x(view, ...rest)`: App do gói tự truyền. */
type Controller<F> = F extends (view: infer V, app: any, ...rest: infer R) => infer O ? (view: V, ...rest: R) => O : never;

/**
 * Nối một view của theme vào controller của gói (start/resume/pause/destroy, guard,
 * state). Theme `.sao` chỉ gọi các hàm này; không import đường dẫn triển khai.
 * Mỗi lần gọi tạo binding RIÊNG cho view đó.
 */
export interface AdminControllers {
    manager: Controller<typeof bindManager>;
    shell: Controller<typeof bindShell>;
    page: Controller<typeof bindPage>;
    resource: Controller<typeof bindResource>;
    settings: Controller<typeof bindSettings>;
    languages: Controller<typeof bindLanguages>;
    mediaLibrary: Controller<typeof bindMediaLibrary>;
    input: Controller<typeof bindInput>;
    ui: Controller<typeof bindUi>;
}

/**
 * API công khai của gói admin, gọi qua `App.Admin`. View/theme/project chỉ dùng
 * bề mặt này, không import đường dẫn triển khai của gói.
 *
 * Contract 1. Mỗi `create*` trả instance RIÊNG của view gọi nó (draft, lỗi,
 * lựa chọn dòng, upload); service không giữ trạng thái đó. Trạng thái dùng chung
 * duy nhất là page đang mở và các guard đổi ngôn ngữ, sống trong một Application.
 */
export interface AdminApi {
    /** Controller nối view của theme vào gói. */
    readonly controllers: AdminControllers;

    createResourceManager(resource: Management): ResourceManager;
    createSettingsForm(editor: Editor): SettingsForm;
    createLanguageForm(editor: LanguageEditor): LanguageForm;
    createMediaLibrary(editor: MediaEditor, imageOnly?: boolean): MediaLibraryForm;

    /** Ghi page đang mở; shell theo dõi qua `watchCurrentPage`. */
    setCurrentPage(key: string): void;
    /** Gọi ngay nếu đã có page; trả hàm hủy đăng ký. */
    watchCurrentPage(listener: (key: string) => void): () => void;

    /** Guard chặn đổi ngôn ngữ khi form còn thay đổi chưa lưu; trả hàm gỡ. */
    registerLanguageGuard(guard: () => boolean): () => void;
    canChangeLanguage(): boolean;
    /** Xóa guard còn sót (test, rời panel). Không đụng page đang mở. */
    reset(): void;
}

// `Application` (class) và `ApplicationInterface` đều phải khai: class implements
// interface nên chỉ khai một bên thì tsc báo class thiếu thuộc tính.
//
// Kiểu tham chiếu bằng ĐƯỜNG DẪN ALIAS, không bằng `AdminApi` cục bộ: builder chép
// `app/` sang cây sinh ra (`resources/js/saola/admin/app`) và tsconfig của host
// thường quét cả hai cây. Hai bản khai báo cùng augment `Application.Admin` bằng hai
// kiểu khác nhau là lỗi TS2717; trỏ cùng một module thì hai bản khai báo trùng nhau.
declare module '@saolabs/client' {
    interface Application { Admin: import('@sao/admin/app/contracts/admin').AdminApi }
    interface ApplicationInterface { Admin: import('@sao/admin/app/contracts/admin').AdminApi }
}

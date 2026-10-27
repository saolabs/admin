# API công khai `App.Admin`

Contract 1, nguồn đang phát triển sau alpha.1 (chưa phát hành). Kiểu TypeScript nằm ở `resources/saola/admin/app/contracts/admin.ts` và đi cùng bundle; `App.Admin` có kiểu nhờ khai báo bổ sung cho `@saolabs/client`. View, theme và project dùng API này, không import đường dẫn triển khai (`app/views/*`, `app/*-form.ts`). Chỉ API được liệt kê ở đây là công khai; mọi thứ khác là chi tiết nội bộ có thể đổi.

`App.Admin` và `App.make('AdminUI')` là cùng một service. `AdminUI` là tên cũ, giữ cho host chưa đổi; mã mới dùng `App.Admin`.

`controllers.ui(view, readData, kind)` quản lý tương tác của [bộ Material UI](UI_COMPONENTS.md). Data types được export từ `app/contracts/ui`; presentation dùng `components/ui/*.sao` cùng CSS Velzon đã đóng gói. API bổ sung này không đổi Resource/Settings/Media contract.

## Vòng đời của service

Một instance cho mỗi `Application`, tạo khi đăng ký bằng factory (không phải instance ở phạm vi module). Hai Application không chia sẻ trạng thái. Service chỉ giữ hai thứ dùng chung: page đang mở và các guard đổi ngôn ngữ. Draft, lỗi validation, lựa chọn dòng, trạng thái upload và request đang chạy thuộc instance form/controller của từng view.

## Form và quản lý dữ liệu

Mỗi `create*` trả instance riêng cho view gọi. Gọi `suspend()` khi view tạm dừng (hủy request, bỏ phản hồi muộn) và `destroy()` khi có (chỉ `ResourceManager`). Các form không tự đăng ký listener; controller của gói làm việc đó và tháo khi `paused/stopped/destroyed`.

| API | Bắt đầu | Đầu vào | Đầu ra / lỗi | Override |
| --- | --- | --- | --- | --- |
| `createResourceManager(resource)` | contract 1 | `Management` (schema, endpoint, fields, rows, CSRF) từ server | `ResourceManager`; lỗi mạng/422/409/401/403/419 thành trạng thái và `notice`, không ném | Không |
| `createSettingsForm(editor)` | contract 1 | `Editor` (group, document, fields, contentLocale, endpoint, csrfToken, canUpdate) | `SettingsForm`; xung đột 409 buộc tải lại, write chưa xác nhận buộc tải lại | Không |
| `createLanguageForm(editor)` | contract 1 | `LanguageEditor` (document, permissions) | `LanguageForm` | Không |
| `createMediaLibrary(editor, imageOnly = false)` | contract 1 | `MediaEditor` (endpoint, csrfToken, maxBytes, accept, danh sách đầu) | `MediaLibraryForm` | Không |

## Page và guard

| API | Chức năng |
| --- | --- |
| `setCurrentPage(key)` | Ghi page đang mở (bỏ qua khóa rỗng hoặc không đổi). Page `.sao` gọi qua `controllers.page` |
| `watchCurrentPage(listener)` | Gọi ngay nếu đã có page; trả hàm hủy đăng ký. Shell dùng để tô sáng mục menu, kể cả khi view cache resume |
| `registerLanguageGuard(guard)` | Thêm guard `() => boolean`; trả hàm gỡ. Controller form đăng ký khi active và gỡ khi pause/destroy |
| `canChangeLanguage()` | `true` khi mọi guard cho phép |
| `reset()` | Xóa mọi guard (test, rời panel). Không đụng page đang mở |

## Controller cho theme

`App.Admin.controllers.{tên}($view, () => props, …)` nối view của theme vào controller của gói: action, dirty guard, focus, start/resume/pause/destroy. Mỗi lần gọi tạo binding riêng cho view đó; props đọc qua getter để nhận dữ liệu mới khi view cache resume. Hàm trả về các action/callback mà markup dùng.

| Controller | View của theme | Ghi chú |
| --- | --- | --- |
| `shell($view, props, presentation?)` | `layouts.shell` | `presentation` (`ShellPresentation`) là adapter hiệu ứng tùy chọn của theme |
| `page($view, currentPage)` | `pages.*` | Ghi page đang mở |
| `resource($view, props)` | `components.resource` | Trang danh sách/biểu mẫu resource |
| `manager($view, props)` | `components.manager` | CRUD: draft, section, picker media |
| `input($view, props)` | `components.input` | Một ô nhập của resource |
| `settings($view, props)` | `components.settings-form` | Settings |
| `languages($view, props)` | `components.languages` | Ngôn ngữ |
| `mediaLibrary($view, props)` | `components.media-library` | Thư viện media |

Renderer bắt buộc của theme là các khóa `renderers` trong `context.manifest.json` (`admin:doctor` kiểm). Theme chỉ được khai view, helper và service trong namespace trình bày; không đăng ký lại API CRUD, quyền, validation hay service nghiệp vụ.

## Điểm override

Manifest khai `overridable: ["Admin.Presentation"]` là phạm vi project/theme được thay. Hiện chưa có service nào mang tên đó và client chỉ kiểm khi bật `strictBundles`; xem [kế hoạch](../../docs/CONTEXT_PACKAGE_IMPLEMENTATION_PLAN.md). Mọi API ở trên hiện không override được.

## Ví dụ

```ts
// theme/views/pages/settings.sao — script setup
App.Admin.controllers.page($view, () => 'settings');
```

```ts
// bootstrap của host: gói admin đi qua bundle, không import file triển khai
import {defineBundle} from '@saolabs/client';
import admin from './bundle';
export default defineBundle({...admin, name: 'host/admin'});
```

## Khai báo kiểu trong host

Ở chế độ nguồn gói P4, builder sinh `types/contexts.d.ts` và `tsconfig.contexts.json`, trỏ alias về cây nguồn hiệu lực. Project không phải chép khai báo namespace từ gói. Cách dưới đây áp dụng cho chế độ source publish cũ.

Builder chép `app/` sang `resources/js/saola/admin/app`; tsconfig của host quét cả hai cây. Vì thế phần khai báo bổ sung trong `app/contracts/admin.ts` trỏ kiểu bằng alias `@sao/admin/app/contracts/admin`, không bằng kiểu cục bộ (nếu không, `tsc` báo TS2717). Host cần alias `@sao/*` → `resources/saola/*` (đã có trong tsconfig starter).

## Tương thích

- `_shared/app/*` đã bỏ khỏi nguồn runtime đang phát triển. Publisher đọc ownership cũ, dọn file do gói sở hữu chưa sửa và dừng khi có customization/import cũ trong bootstrap riêng của host. Chuyển logic sang `app/`, theme dùng `App.Admin.controllers.*`.
- `App.make('AdminUI')` còn chạy; chuyển sang `App.Admin`.
- Theme cũ gọi `bindX($view, App, …)` từ `@sao/admin/app/views/*` còn chạy trong bản này nhưng không còn là API công khai; chuyển sang `App.Admin.controllers.*`.

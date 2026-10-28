# Kế hoạch: registry admin (menu, list, form) thay cho Page/Resource

Trạng thái: **đã triển khai** (28/10/2026, chưa phát hành tag). Q6/Q12/Q14 theo đề xuất; chi tiết ở mục 7. Hướng dẫn dùng: [RESOURCES.md](RESOURCES.md).

## 1. Mục tiêu và nguyên tắc

- **Backend chỉ xử lý nghiệp vụ và cung cấp dữ liệu.** Module (Model, Repository, Service, controller theo context) không chứa class giao diện nào: không Page, không Resource.
- **Cấu hình giao diện là dữ liệu, đăng ký trong `ModuleServiceProvider`** của module (chỗ module tự nối với hệ thống, như route). Thay cho `list.json`/`form.json` của ai-services, nhưng khai bằng PHP nên được kiểm tra và tự động hoàn thành.
- **Một khai báo trường cho ra ba thứ:** cách vẽ (frontend), validate (backend và frontend), thông báo lỗi (hai phía dùng chung).
- **Xuất được ra JSON** để frontend tự dựng sidebar, bảng, form theo theme; backend không biết cách vẽ.

## 2. Thay gì, giữ gì

| Bỏ (sau khi chuyển xong) | Giữ, đổi nguồn dữ liệu |
| --- | --- |
| `Page`, `Resource`, `NavigablePage`, `ModulePage`, `GroupedPage`, `LinkedPage` | Theme material/classic, component bảng/form |
| `PageDiscovery` (quét `Modules/*/{Resources,Pages}`), `panel.pages`, `panel.icons` | Input theo loại (`components/inputs/*`), view riêng của dự án (`views/inputs`, `views/columns`) |
| `Resource::fields()/columns()/rules()`, `Input::make`, `type` + `template` | `ModuleService` + repository core (đọc/ghi, sự kiện CRUD) |
| Gehihi: `GrantsPermissions`, các class trong `Modules/*/Pages`, `Modules/Users/Resources` | Settings, media, ngôn ngữ (đăng ký lại theo cùng cách, xem câu hỏi Q8) |
| `admin:make:resource`, `admin:make:screen` | `HostScreenController` đổi thành `Saola\Admin\Http\AdminController`, thêm hàm CRUD |

## 3. Thiết kế đề xuất

### 3.1 Đăng ký

```php
// app/Modules/Users/ModuleServiceProvider.php, boot()
Admin::menu('users', [
    'title'   => 'gehihi.admin.users.title',
    'icon'    => 'users',
    'order'   => 30,
    'route'   => 'admin.users.index',
    'ability' => 'gehihi.admin.users.view',
]);

Admin::list('users', [
    'service' => UserService::class,
    'columns' => [
        'name'  => ['label' => 'admin.users.name'],
        'email' => ['label' => 'admin.users.email'],
    ],
    'search'  => ['name', 'email'],
    'sort'    => ['name', 'email'],
]);

Admin::form('users', [
    'service'  => UserService::class,
    'messages' => ['required' => 'gehihi.validation.required'],   // mặc định cho cả form
    'fields'   => [
        'name' => [
            'type'  => 'text',
            'label' => 'admin.users.name',
            'rules' => ['required', 'string', 'max:100'],
        ],
        'email' => [
            'type'     => 'email',
            'label'    => 'admin.users.email',
            'rules'    => ['required', 'email', 'max:200', 'unique:users,email'],
            'messages' => ['unique' => 'gehihi.validation.email_taken'],
        ],
        'password' => [
            'type'     => 'password',
            'label'    => 'admin.resource.password',
            'rules'    => ['required', 'min:12', 'confirmed'],
            'messages' => ['min' => 'gehihi.validation.password_min'],
        ],
    ],
]);
```

### 3.2 Khoá của một trường (form)

Mỗi khoá chỉ làm một việc, không suy ra từ khoá khác.

| Khoá | Việc | Ghi chú |
| --- | --- | --- |
| `type` | Loại input (giao diện) | text, email, password, number, textarea, select, radio, checkboxes, boolean, date, media, richtext, markdown, json, tags... hoặc loại riêng của dự án (`views/inputs/{type}.sao`) |
| `label` | Nhãn | Khoá dịch |
| `rules` | Validate | Cú pháp Laravel (mảng) |
| `messages` | Thông báo lỗi theo quy tắc | `['min' => 'khoá dịch']` |
| `placeholder`, `help` | Gợi ý | Khoá dịch |
| `options` | Lựa chọn cho select/radio/checkboxes | Tĩnh, hoặc lấy từ service (Q4) |
| `default` | Giá trị mặc định khi tạo mới | |
| `section`, `span` | Bố cục | Nhóm/tab, nửa hoặc cả dòng |
| `readOnly`, `writeOnly` | Chỉ đọc / chỉ ghi | `writeOnly` cho mật khẩu, secret |
| `persist` | Có lưu vào cột không | `false` cho trường xác nhận, trường xử lý trong service |

### 3.3 Validate và thông báo lỗi

- **Backend:** đưa thẳng `rules` và `messages` cho Laravel validator. Backend luôn là nơi quyết định.
- **Frontend:** nhận các quy tắc tự kiểm được (`required`, `email`, `url`, `min`, `max`, `between`, `in`, `numeric`, `integer`, `regex`, `confirmed`, `date`...). Quy tắc frontend không hiểu (`unique`, `exists`, Rule tự viết, closure) tự động chỉ chạy ở backend; frontend hiện lỗi server trả về đúng vị trí trường.
- **Ưu tiên thông báo:** `messages` của trường, rồi `messages` của form, rồi thông báo chung `validation.{rule}`.
- **Tham số** (`:attribute`, `:min`, `:max`, `:values`...) thay theo cùng một quy tắc ở hai phía; `:attribute` lấy từ `label` đã dịch.
- **Mọi thông báo là khoá dịch** trong catalog ngôn ngữ (DB), sửa được trong màn Ngôn ngữ.

### 3.4 List

| Khoá | Việc |
| --- | --- |
| `service` | Service của module cung cấp dữ liệu (ModuleService → repository) |
| `columns` | Mỗi cột: `label`, `type` (text, badge, image, link, boolean, number, date, hoặc loại riêng `views/columns/{type}.sao`) |
| `search`, `sort`, `filters` | Cột cho tìm, sắp, lọc (whitelist: request chỉ dùng được những cột này) |
| `perPage` | Mặc định và các lựa chọn |
| `actions` | Sửa, xoá, khôi phục, thao tác hàng loạt; mỗi thao tác có `ability` |
| `trash` | Có thùng rác không (theo `deleteMode` của model) |

### 3.5 Menu

| Khoá | Việc |
| --- | --- |
| `title`, `icon`, `order` | Hiển thị và thứ tự |
| `route` | Tên route đích (màn CRUD chung hoặc màn hình đặc thù của module) |
| `ability` | Quyền xem; mục ẩn nếu người dùng không có |
| `group` | Nhóm cha (menu con) |

Mục của gói (Media, Ngôn ngữ, Cài đặt) đăng ký theo cùng cách trong provider của gói.

### 3.6 Luồng dữ liệu

1. Module đăng ký `menu`/`list`/`form` trong `boot()`.
2. `Admin::toArray($user)` dựng cấu hình đã lọc theo quyền; frontend nhận qua dữ liệu chia sẻ của trang (SSR/SPA) hoặc endpoint (Q7).
3. Các hàm CRUD của `Saola\Admin\Http\AdminController` (module kế thừa qua `App\Http\Controllers\AdminController`, tự khai route), theo khoá của cấu hình (Q12):
   - list: gọi `service->getResults($request)` với whitelist `search/sort/filters`;
   - form: validate theo `rules/messages`, rồi `service->create/update` (ModuleService → repository → sự kiện CRUD core);
   - xoá/khôi phục: `moveToTrash/restoreFromTrash`.
4. Nghiệp vụ riêng (đồng bộ vai trò khi lưu user, chặn nhân sự sửa chủ) nằm trong service của module, không trong cấu hình.
5. Màn hình đặc thù: chỉ đăng ký `menu` trỏ tới route của module; controller trả dữ liệu, view `.sao` của context tự vẽ.

## 4. Các bước triển khai (sơ bộ)

0. **Core:** thêm `WebController`, `AdminController`, `ApiController` theo context (Q13), template `make:module` giữ nguyên `App\Http\Controllers\*`; phát hành core.
1. **Gói:** class `Admin` (registry + kiểm tra cấu trúc khi đăng ký), xuất JSON, controller CRUD chung, theme đọc JSON mới. Kèm test: đăng ký sai khoá báo lỗi; quy tắc frontend/backend tách đúng; thông báo lỗi đúng thứ tự ưu tiên.
2. **Song song một thời gian:** Page/Resource cũ vẫn chạy để chuyển dần (hoặc bỏ thẳng vì gói còn alpha, Q10).
3. **Gehihi:** chuyển Users, Roles, các trang AI sang đăng ký trong provider; bỏ class Page/Resource và `GrantsPermissions`.
4. **Dọn:** bỏ Page/Resource, `PageDiscovery`, lệnh `admin:make:*` cũ; cập nhật docs; phát hành bản alpha mới.

## 5. Đã chốt (28/10/2026)

- **Q1 – Phạm vi:** `Admin` là của gói admin, chỉ dùng cho admin.
- **Q2 – Dạng khai báo:** mảng, cho cả form, list và menu.
- **Q3 – Validate:** đi qua `Validator` của core; core bọc Laravel validator nên nhận cú pháp quy tắc Laravel. Laravel có gì mới thì nâng cấp trong core, không viết validate riêng ở gói.
- **Q8 – Settings:** module tự khai báo form cài đặt của mình nếu cần, theo chuẩn khai báo của context admin.
- **Q10 – Tương thích:** bỏ thẳng Page/Resource (gói đang alpha), không chạy song song.
- **Q11 – Kiểm tra cấu hình:** khi khởi động; sai khoá, sai loại input hay quy tắc thì báo lỗi ngay.
- **Q13 – Controller nền theo context nằm ở core.** Core cấp `Saola\Core\Http\Controllers\WebController`, `AdminController`, `ApiController` (đặt sẵn `$context`; giữ `BaseWebController`/`BaseApiController` để tương thích). Dự án luôn có `App\Http\Controllers\{Web,Admin,Api}Controller` và chỉ khác nhau ở dòng `extends`:

  | Dự án | `App\Http\Controllers\AdminController` extends |
  | --- | --- |
  | Không cài `saola/admin` | `Saola\Core\Http\Controllers\AdminController`: admin tự viết bằng controller + view của context admin |
  | Có cài `saola/admin` | `Saola\Admin\Http\AdminController` (lớp này extends lớp admin của core): thêm hàm CRUD, menu, shell |

  Controller của module không đổi gì khi cài hay gỡ gói. Phần tương tự cho web/api: gói khác (ví dụ `saola/api`) cũng chỉ cần cấp lớp extends lớp tương ứng của core.
- **Q4 – Lựa chọn từ DB:** `'options' => [RoleService::class, 'options']`; gói gọi hàm của service mỗi lần dựng form, service quyết định trả những dòng nào.
- **Q5 – Quyền theo trường:** khoá `'ability'` trên trường; người không có quyền không thấy trường và server bỏ qua giá trị họ gửi.
- **Q7 – Giao cấu hình:** gửi kèm dữ liệu mỗi trang (menu + form/list của chính trang đó), có ngay khi SSR.
- **Q9 – Route và controller:** gói không cấp route chung. Chuỗi kế thừa ba tầng:

  ```
  App\Modules\Users\Http\Controllers\Admin\UserController   (module: tự khai route, override khi cần)
    extends App\Http\Controllers\AdminController              (dự án: chỗ tuỳ biến chung cho mọi màn admin)
      extends Saola\Admin\Http\AdminController                 (gói: hàm CRUD + dữ liệu shell/menu)
        extends Saola\Core\Http\Controllers\AdminController    (core: controller nền của context admin, Q13)
  ```

  `Saola\Admin\Http\AdminController` thay `HostScreenController` hiện có, chứa sẵn các hàm danh sách, tạo, lưu, sửa, cập nhật, xoá, khôi phục, đọc cấu hình list/form đã đăng ký. `App\Http\Controllers\AdminController` là lớp của dự án (template `make:module` của core đã sinh controller admin kế thừa lớp này), dùng cho thay đổi áp dụng mọi màn admin. Controller của module kế thừa lớp dự án, khai route trỏ vào các hàm có sẵn; muốn xử lý khác thì override.

## 6. Từng còn cần thảo luận (đã làm theo đề xuất)

- **Q6 – Trường không phải một cột bình thường.**
  - "Vai trò" của user không lưu vào bảng `users` mà vào bảng gán vai trò; đề xuất `'persist' => false`, service xử lý khi lưu.
  - Form vai trò có bảng tick quyền (nhóm × thao tác); đề xuất `'type' => 'matrix'`, dữ liệu bảng lấy từ service như Q4.
  - Trường nội dung nhiều ngôn ngữ; đề xuất `'translatable' => true`, form hiện theo từng ngôn ngữ.
- **Q12 – Controller biết dùng cấu hình nào.** Các hàm CRUD kế thừa cần biết đọc list/form với khoá nào. Đề xuất: mặc định lấy từ tên route `admin.{module}.{action}` (như `HostScreenController` đang làm), khai `protected $module = 'users';` trên controller khi muốn chỉ định rõ; khoá menu/list/form trùng tên module.
- **Q14 – Ai đổi dòng `extends` khi cài/gỡ gói admin.** `admin:install` tự sửa `App\Http\Controllers\AdminController` sang lớp của gói (và `admin:doctor` báo nếu chưa đổi), hay chỉ hướng dẫn để người dùng tự sửa? Gehihi hiện còn `ApiController` không kế thừa controller nào của core (tự viết `ok()`, `ws()`); khi có `ApiController` của core thì chuyển sang kế thừa nó.

## 7. Đã triển khai (28/10/2026)

- Core: `Saola\Core\Http\Controllers\{WebController, AdminController, ApiController}`; hook `menus()`/`permissions()` của `ModuleServiceProvider` là chỗ module gọi `Admin::*`.
- Gói: `Admin` + `AdminRegistry` (kiểm khóa khi đăng ký, `verify()` lúc `booted`), `Http\AdminController` (`routes($m)` + action `listPage/createPage/editPage/apiRecords/apiRecord/apiTransition/apiBulk`; tên tách khỏi `index/store/…` để controller module không vỡ chữ ký), `HomeController`. Thêm khóa form `prepare` (chuẩn hóa trước validate). Đã gỡ `Pages/*` (trừ `ResourceDocument`), `Users/*`, `PageController`, `ResourceController`, `HostScreenController`, generator `admin:make:*`, route chung `/{page}` và `/_api/resources/*`.
- Gehihi: module khai menu/list/form trong provider; `Access::declare()` thay `GrantsPermissions`; `RouteAbility` đọc ability từ menu; users/roles qua `UserService`/`RoleService`; context admin prefix `admin`, module AI prefix `ai/...` (URL giữ nguyên).

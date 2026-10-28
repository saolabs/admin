# Theme giao diện của gói saola/admin

Contract 1, source đang phát triển sau alpha.1. Cơ chế theme, manifest, source, publisher và resolver thuộc **gói saola/admin**. Host chỉ tích hợp build và chọn theme; không tạo controller, logic CRUD hay bản sao UI riêng cho từng module.

```text
resources/saola/admin/
  context.manifest.json
  app/                         # Bootstrap/bridge tích hợp host, không có skin
  app/                         # `App.Admin`, state/service dùng chung
    contracts/                 # AdminApi (kiểu công khai)
    views/                     # Controller, action, guard và lifecycle dùng chung
  build/themes.mjs             # Đọc manifest, tạo CSS inputs riêng cho từng theme
  themes/classic/
    theme.manifest.json
    app/
    views/{layouts,pages,components,partials}/
    assets/{css,images}/
  themes/material/
    theme.manifest.json
    app/
    views/{layouts,pages,components,partials}/
    assets/{css,images,vendor}/
```

`classic` giữ giao diện nguyên bản đã có trước lần tách theme. `material` dùng CSS Bootstrap/Velzon Material và icon font từ thư mục `admin-material` được cung cấp, với class card/form/table/sidebar/header/dropdown của distribution đó. File `themes/material/assets/vendor/material/SOURCE.json` ghi nguồn, hash và các bước chuẩn bị asset. Các script demo toàn trang không được chạy vì chúng thay sessionStorage, document và menu ngoài lifecycle Saola. Dropdown, điều hướng, form và dirty guards vẫn nối với lifecycle/state của package.

## Chọn theme và view context

Trong config `saola-admin.php`:

```php
'panel' => [
    // Các cấu hình panel khác vẫn giữ nguyên.
    'theme' => 'material',
],
```

`Themes\AdminTheme` tự đọc các `themes/*/theme.manifest.json` trong package, kiểm tra slug/context/CSS và đăng ký context `admin` với base `admin.themes.material`. Page/controller vẫn dùng `@RAW:pages.*`; shell/component vẫn dùng `__base__`. SSR, page-data, component routes và hydration nhận cùng namespace. Không sao chép route, đăng ký list/form, Policy hay store vào theme.

Mặc định package chọn `classic`. Host kiểm chứng Gehihi chọn `material`. Đổi theme là đổi config rồi khởi động lại worker/xóa config cache theo cách triển khai của host. Chuyển theme bằng tải document đầy đủ để stylesheet và registry của document được chọn đồng bộ; không có endpoint cho khách đổi theme trong một SPA đang mở.

## Build cùng lúc nhiều theme

Khai các namespace vào context admin của toolchain host, độc lập nhau:

```json
{
  "app": ["admin/app"],
  "views": {
    "admin.themes.classic": "admin/themes/classic/views",
    "admin.themes.material": "admin/themes/material/views"
  },
  "blade": {
    "admin.themes.classic": "admin/themes/classic",
    "admin.themes.material": "admin/themes/material"
  }
}
```

Namespace `admin` cũ có thể giữ thêm cho các màn nghiệp vụ chưa chuyển của host. Tất cả theme được compile vào registry; mỗi CSS là một input riêng. Vite sử dụng helper **được đóng gói trong admin**, không viết lại danh sách CSS trong project:

```js
import {adminThemeCss} from './resources/saola/admin/build/themes.mjs';
// Gộp với JS entry của context và các input khác của host:
input: ['resources/js/saola/app.admin.js', ...adminThemeCss(projectRoot)]
```

Document host dùng hook chung `@includeIf($__partial__.'head')` trước stylesheet. Material dùng hook này để đặt các data-attribute chuẩn của distribution trước render; lifecycle client đồng bộ/khôi phục chúng khi mount/pause. Đây là hook context chung, không phải logic theme riêng trong host.

Backend chọn chính xác CSS entry của theme qua BundleManifest. Bundle root chỉ cung cấp service dùng chung, không import CSS của tất cả theme. Vì thế compile nhiều theme không làm các skin đè lên nhau. `admin:doctor` kiểm tra namespace, renderer và CSS artifact tương ứng theme đang chọn.

## Thêm và đóng gói một theme

Thêm `admin/themes/{slug}/`, giữ cấu trúc app/views/assets như trên, và tạo manifest:

```json
{
  "name": "graphite",
  "contract": 1,
  "view_context": "admin.graphite",
  "views": "views",
  "bootstrap": "app/bootstrap.ts",
  "css": "assets/css/admin.css"
}
```

Dùng cùng props/DTO và AdminUI contract; các wrapper trong app có thể re-export `app/*`. Đăng ký namespace mới cho compiler, build rồi chọn `panel.theme=graphite`. Resolver/publisher/CSS helper không có switch hard-code theo tên theme. Cấu hình `themes` là điểm bổ sung descriptor cho một gói theme khác; không nhận PHP class, view path hoặc CSS path từ HTTP request.

Composer archive chứa source của các theme và các manifest trong cùng gói admin. `admin:publish` tự inventory cây source, thêm theme mới cạnh theme cũ, giữ bootstrap host và các file không thuộc package. Có test thêm theme thứ ba mà không thay đổi Classic/Material.

## Phân tách chức năng và trình bày

Chức năng có một implementation trong package, không nhân bản theo theme:

- `app/{resource-manager,settings-form,language-form,media-library}.ts` quản lý dữ liệu, draft, quyền, validation, revision và request.
- `app/views/` nối state vào view, cung cấp action, đăng ký guard và quản lý start/resume/pause/destroy. Mỗi view tạo một binding riêng; draft, request đang chạy và disposer không nằm trong singleton dùng chung giữa các view.
- `themes/{theme}/views/` chỉ có markup, khai báo props/state cho SSR và hydration, cùng lời gọi `App.Admin.controllers.*` ([API](API.md)). Theme không import đường dẫn triển khai của gói; Classic và Material gọi đúng cùng controller. Callback truyền vào component con lấy từ kết quả binding, không viết lại ở theme.
- `themes/{theme}/app/presentation.ts` là adapter tùy chọn cho hiệu ứng trình bày. Material chỉ đặt/khôi phục data-attribute của layout và khai breakpoint. Adapter không gửi request, xử lý quyền, lưu form hay đăng ký dirty guard.

`binding.ts` cô lập cầu nối reactive state của Saola. Props được đọc qua getter để controller nhận dữ liệu mới khi view cache được resume. Controller đăng ký action/lifecycle qua `ViewController.setUserDefinedConfig`; không cần thay compiler hoặc framework. Các selector/focus target trong shell và editor là hook UI mà markup theme phải giữ (ví dụ `resource-field-{key}`, `resource-notice`, `media-library` và các nút `admin-*-toggle`). CSS/class bổ sung vẫn thuộc theme.

Thêm theme mới bằng cách viết markup theo props/state/action contract này rồi gọi `App.Admin.controllers.{tên}($view, () => props)` tương ứng. Không sao chép script CRUD, settings, language, media hay shell từ theme cũ. Test compile kiểm tra cả hai theme; test thao tác dùng cùng controller qua output compiler thật.

## Nâng cấp từ source phẳng

Manifest gốc khai rename `views/*` → `themes/classic/views/*`, `assets/*` → `themes/classic/assets/*` và `_shared/app/*` → `app/*`. Runtime chỉ còn `app/`, không phát kèm cây re-export `_shared`. Publisher chỉ dọn bản cũ đã có ownership và chưa tùy biến; khi có sửa local ở đường dẫn đổi chỗ hoặc bootstrap riêng còn import nguồn cũ, toàn bộ publish dừng với conflict để giữ bản sửa. Root `app/bundle.ts` cung cấp API dùng chung, bootstrap host tiếp tục import `./bundle`; các theme gọi `App.Admin.controllers.*`. Thư mục chứa file host khác được giữ nguyên.

Cập nhật package local, dry-run/publish, thêm namespace/compiler và CSS inputs như trên rồi build. Không sửa tay Blade/JS/public generated. Không đổi dữ liệu, quyền hoặc schema DB khi đổi theme.

Nguồn của từng theme hiện nằm trong `admin/themes/{name}`. Namespace logic vẫn là `admin.themes.classic`, `admin.themes.material`; Blade output vẫn ánh xạ riêng theo namespace. Manifest giữ cả rename từ source phẳng và từ `admin/{name}` để nâng cấp các lần phát triển trước. Nhiều đường dẫn lịch sử có thể trỏ tới cùng một đích; publisher kiểm ownership và conflict ở mọi đường dẫn trước khi thay đổi.

## Phân vai view trong context admin

| Thư mục (trong theme) | Dành cho | Ai viết | Tên view |
| --- | --- | --- | --- |
| `pages/` | Màn hình dùng chung của gói: `resource` (mọi CRUD khai bằng `Admin::list/form`), `settings`, `languages`, `media`, `ui-library` | Gói | `admin.themes.<theme>.pages.*`, controller của gói chỉ định tường minh vì một view phục vụ nhiều route |
| `components/`, `layouts/`, `partials/` | Thành phần dùng lại, khung shell, phần đầu trang | Gói | `admin.themes.<theme>.components.*` … |
| `modules/` của **host** (`saola-admin.panel.host_modules`) | Màn hình nghiệp vụ riêng của ứng dụng, tên view khớp tên route (`admin.providers.index` → `providers/index.sao`) | Host | `<host_modules>.providers.index` |

CRUD tiêu chuẩn không cần view: module khai `Admin::list/form` (cột, trường, rules, quyền) trong provider, gói dựng danh sách, form, thùng rác và thao tác hàng loạt bằng `pages/resource`. `Saola\Admin\Http\AdminController` lấy module và action từ tên route để chọn view trong `host_modules` cho màn hình riêng. Input, ô danh sách và cả màn hình của từng list/form có thể là view của project: xem [RESOURCES.md](RESOURCES.md#tùy-biến-khi-cần-view-của-project-cùng-chạy-ssr-hydrate-và-csr).

## Input: mỗi type một file

`components/input.sao` chỉ giữ nhãn, trợ giúp, lỗi, danh sách `inputKinds` và một dòng `@include` chọn `components/inputs/{kind}`: tên file là `template` của trường (luôn có; mặc định bằng `type`) nếu nằm trong danh sách, không thì `text`.

| File | Loại |
| --- | --- |
| `text` | mặc định: text, email, url, tel, password, integer, number, range, date, datetime, time, color |
| `boolean`, `switch` | boolean (`switch` dùng lại `boolean`) |
| `select`, `relation`, `multiselect` | select, relation (có ô lọc; dùng lại `select`), multiselect |
| `radio`, `checkboxes` | nhóm lựa chọn (`checkboxes` dùng lại `radio`) |
| `matrix` | ma trận quyền một bảng |
| `media`, `image` | media/image (`image` dùng lại `media`) |
| `tags` | tags |
| `textarea`, `markdown`, `richtext`, `json` | vùng văn bản (ba loại sau dùng lại `textarea`, có thanh công cụ riêng) |

Thêm type mới: tạo `inputs/<tên>.sao` (hoặc một file `@include` file có sẵn) và thêm tên vào `inputKinds` trong cả hai theme; type phải có ở `Input::TEMPLATES`.

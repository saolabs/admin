# Quy ước tên admin và bảng dùng chung

Source hiện tại đã tách giao diện thành `admin/{theme-name}/` trong **gói admin**. [Contract theme, build và đường nâng cấp](THEMES.md) là hướng dẫn hiện hành cho vị trí file, view context và CSS. Các mô tả skin nguyên bản/đường dẫn phẳng bên dưới là lịch sử của theme Classic trước lần tách này.

Chốt 24/10/2026: `admin` dùng cho context, giao diện và client; bảng dùng chung mặc định chỉ có prefix `saola_`, có thể cấu hình hoặc bỏ.

```text
resources/saola/admin/
  app/
    bootstrap.ts
    bundle.ts
    settings-form.ts
  views/
    pages/{resource,settings}.sao
    layouts/shell.sao
    components/{resource,settings-form}.sao
    partials/head.sao
  assets/{css,images}/
  context.manifest.json
```

| Phần | Tên hiện tại |
| --- | --- |
| Composer / PHP namespace | `saola/admin` / `Saola\Admin` |
| Config package / publish tags | `saola-admin.php`, `saola-admin-config`, `saola-admin-lang` |
| Context / prefix URL | `admin` / `/admin` (prefix URL có cấu hình) |
| View logical | `admin.pages.settings`, `admin.layouts.shell`, `admin.components.settings-form` |
| Client bootstrap | `app/bootstrap.ts` import `./bundle` |
| Dictionary scope / endpoint | `admin` / `/saola/languages/admin/{locale}` (scope có cấu hình) |
| Gate vào panel | `access-admin` (ability có cấu hình, không tự cấp grant) |
| Bảng chung | `saola_settings`, `saola_locales`, `saola_translation_keys`, `saola_translation_values`, `saola_language_batches`, `saola_language_settings` |

Tên file lang/owner/package không làm prefix translation key. CSS giữ selector scope `.saola-admin` và token `--sa-admin-*` để style không tác động class chung của host; đây không là một tầng namespace view, thư mục hoặc tên bảng.

## Prefix DB tùy chọn

`saola-admin.language.table_prefix` mặc định `saola_`; settings dùng tên bảng đầy đủ tại `saola-admin.settings.table`, mặc định `saola_settings`. Có thể dùng `table_prefix=''` và `table='settings'`, hoặc prefix riêng. Nếu Laravel connection đã có `prefix='saola_'`, đặt prefix language rỗng và settings.table='settings' để SQL không thành `saola_saola_*`. Không tự gỡ prefix của host.

Workbench kiểm default, prefix rỗng, prefix tùy chỉnh, connection prefix và cấu hình tên cũ; migrations/store/exchange cùng đọc cấu hình. Config không tự đổi tên hoặc sao chép DB.

## Nâng từ source phát triển trước khi rút tên

Đây là thay đổi của bản chưa phát hành. Deploy source/backend/Blade/JS cùng lượt; build trong release/worktree sạch, không dùng registry hoặc Blade cũ với namespace mới. Bỏ cache config/route/view theo quy trình deploy host; các import/controller/override của host còn gọi `admin.saola_admin.*` hoặc `./saola_admin/bundle` phải chuyển sang tên ở bảng trên.

Manifest có map `renames` từ các file `app/saola_admin/*`, `views/saola_admin/*` sang đường dẫn ngắn. `admin:publish --dry-run` lập kế hoạch add/remove cùng batch. File cũ nguyên bản do admin sở hữu được chuyển; chỉ xóa parent directory khi đã rỗng. Host/theme/custom files được giữ.

Nếu view cũ đã sửa hoặc bootstrap custom còn import bundle cũ, publisher dừng trước khi thay file để tránh orphan customization. Lưu patch/Git snapshot của bản sửa, khôi phục baseline cũ đã biết, publish nguồn mới rồi áp patch vào vị trí mới đã được nhận ownership. Với bootstrap custom không thuộc package, merge import `./bundle` và service/providers của host trước publish. Không sửa tay state để tự nhận quyền sở hữu file hoặc dùng force.

DB đã có `saola_admin_*`: giữ cấu hình `language.table_prefix='saola_admin_'`, `settings.table='saola_admin_settings'` cho tới khi có migration đổi tên DB được review riêng. `admin:doctor` phát hiện bảng legacy khi default mới không tồn tại và báo cấu hình cần giữ; không rename/drop/seed DB. Initial migrations hiện dùng mặc định mới cho cài mới; đổi config không khiến migrations đã chạy tự chạy lại. Không chạy migrate:fresh để đổi tên trên DB có dữ liệu.

Host đã khai Gate/scope cũ có thể giữ `panel.ability='access-saola-admin'`, `panel.language_scope='saola-admin'` trong config hiện có trong lúc chuyển code. Không tạo alias Gate tự cấp quyền. Starter hiện chưa được nâng dependency hoặc sửa DB thật trong đợt này. Alpha/package versions xem [RELEASE](../RELEASE.md).

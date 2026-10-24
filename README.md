# saola/admin

Gói quản trị dùng chung cho ứng dụng Saola/Laravel: quản lý người dùng, phân quyền và thiết lập website; hỗ trợ đa ngôn ngữ và khai báo Resource/Settings để giảm viết lại giao diện `.sao`.

**Alpha 0.1.0-alpha.1:** đã có shell responsive, Page/Resource renderer chỉ đọc, auth/Gate, lang DB, dictionary/JSON preview-draft-publish, install/publish/doctor và Settings schema/DB/API và form dùng chung có revision. [Danh sách user](docs/USERS.md) lấy model của guard host, scope/Policy, tìm kiếm/phân trang và dùng chung renderer. Chưa có mutation User/role, UI dịch/AI; phạm vi alpha xem [RELEASE](RELEASE.md).

Đã có trên [Packagist](https://packagist.org/packages/saola/admin); [kiểm chứng phát hành](docs/RELEASE_VERIFICATION.md).

Quy ước tên: `admin.pages.settings`, `app/bundle.ts`; các bảng mặc định `saola_settings`, `saola_locales`, `saola_translation_*`, `saola_language_batches`. Prefix DB có thể bỏ/đổi. Xem [NAMING](docs/NAMING.md) về đường nâng cấp an toàn.

Xem [kế hoạch phát triển và đóng gói](docs/ADMIN_PACKAGE_PLAN.md). Gói nối Gate/Policy của host về `saola/permission`, dùng User model của ứng dụng và cung cấp bộ renderer chung; nghiệp vụ chuyên sâu thuộc ứng dụng hoặc package mở rộng.

Admin chuẩn bị điểm tích hợp và giao diện tương thích theme; chức năng quản lý theme sẽ được đóng gói riêng sau này.

Toàn bộ source giao diện và tài nguyên admin nằm trong `resources/saola/admin` với `app/`, `views/`, `assets/` và manifest; nguồn lang backend nằm riêng ở `lang/{locale}/*.php`, giữ cùng cấu trúc trong package và sau publish. Ứng dụng build cây này bằng toolchain Saola; tài nguyên admin không được copy rải sang các thư mục dùng chung của website.

Lang dùng cây key–value lồng tùy ý → text; file chỉ khai báo catalog/seed, bản dịch runtime nằm trong DB. Người quản trị xuất/nhập một JSON tổng hoặc dùng AI dịch vào DB; client tải locale/revision lúc chạy, không cần build lại khi thêm ngôn ngữ. Xem [thiết kế lang động](docs/LANGUAGE_SYSTEM_PLAN.md).

Quy ước chung project/package và hướng tích hợp tiếp: [thiết kế lang](docs/LANGUAGE_SYSTEM_PLAN.md). Package tự đăng ký nguồn `lang/{locale}/{admin,common}.php`; tùy chọn publish bằng tag `saola-admin-lang` vào `lang/vendor/admin`. Vị trí/tên file không thêm prefix vào key của Saola.

Sau khi package được cài trong host và migrations đã chạy có chủ đích:

```bash
php artisan admin:lang:sync --dry-run
php artisan admin:lang:sync
php artisan admin:lang:export ja --ticket > ja.json
php artisan admin:lang:import ja ja.json --export=EXPORT_UUID
php artisan admin:lang:apply IMPORT_UUID
php artisan admin:lang:apply IMPORT_UUID --publish
```

CLI export xuất đủ cây, dùng source làm mẫu cho key chưa dịch; UUID baseline nằm ở stderr. Import tạo preview, apply giữ draft và `--publish` mới cập nhật runtime. Giữ source chưa dịch, chuỗi rỗng, key không gửi và chặn conflict/placeholder/duplicate key. UI upload chưa có; `LanguageExchange` cung cấp backend dùng lại.

Dictionary `GET /saola/languages/{scope}/{locale}` chỉ trả allowlist `sao.i18n.client_scopes`. Scope public phải khai báo rõ; private cần user và Gate ability. SSR dùng `ClientCatalogs` cùng projection, client đổi locale qua `Helper.changeLanguage` có guard. Xem [hướng dẫn tích hợp](docs/INSTALLATION.md) và hợp đồng chung về cấu hình, cache, giới hạn import và trạng thái triển khai.

Hướng dẫn [cài đặt/nâng cấp](docs/INSTALLATION.md), [hợp đồng shell/renderer](docs/UI_CONTRACT.md), [Settings schema/DB/form](docs/SETTINGS.md) và [inventory tài nguyên](docs/ASSET_INVENTORY.md). Panel tắt mặc định; host chọn guard/login/Gate/prefix, migrate/sync và build trước khi bật. Install chỉ publish frontend vào root admin, giữ bootstrap có sẵn và báo phần cần merge; không tự cấu hình tài khoản hoặc ghi DB.

```bash
php artisan admin:install --dry-run
php artisan admin:install
# Tích hợp bootstrap/context, migrate/sync rồi build theo hướng dẫn.
php artisan admin:publish --dry-run
php artisan admin:doctor
```

Cài đặt và kiểm tra độc lập:

```bash
composer install
composer test
npm ci --ignore-scripts
admin_preview_root=$(mktemp -d /tmp/saola-admin-preview.XXXXXX)
SAOLA_ADMIN_PREVIEW_DIR="$admin_preview_root" vendor/bin/phpunit --filter test_ssr_compiles
npm run build:preview -- "$admin_preview_root"
```

Bộ dependency cho alpha: core >=2.4, compiler >=3.0; frontend dùng GitHub tags client v1.3.0 và builder v1.2.0, đã pin trong package-lock.json của workbench. Không coi npm registry đã có các phiên bản này. Compiler 3 đổi bare `__()` trong `.sao` sang `text()`; view dùng Laravel translator phải chuyển sang `trans()`/`choice()` trước khi nâng.

Tests/package build không cần sibling checkout; fixture host nằm trong tests/Fixtures/host và không được installer chép vào website. CI kiểm PHP 8.3/8.5 × Laravel 12/13, build nguồn publish với các gói đã tải và Composer archive. Xem [release và cách cài alpha](RELEASE.md), [installation](docs/INSTALLATION.md). MySQL/Postgres concurrency, UI/mobile trên host thực và chức năng User/role ghi vẫn cần nghiệm thu trước bản ổn định.

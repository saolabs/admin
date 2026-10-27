# saola/admin

Source mới tổ chức UI thành [theme của package](docs/THEMES.md) trong `resources/saola/admin/themes/{name}/`: Classic giữ giao diện cũ, Material dùng distribution được cung cấp. Mỗi theme có app/views/assets và manifest riêng; `app/` giữ state, controller và guards, gọi qua `App.Admin` (kiểu ở `app/contracts/admin.ts`, danh mục ở [docs/API.md](docs/API.md)). Đổi `panel.theme` chọn view context và CSS.

CRUD có [bộ input template dựng sẵn](docs/RESOURCES.md#input-template-và-bố-cục-dựng-sẵn), chia nhóm/tab, chọn quan hệ theo scope và media picker. Xem [CatalogResource mẫu](examples/CatalogResource.php); project khai schema và nghiệp vụ, các theme dùng chung JS.

Gói quản trị dùng chung cho ứng dụng Saola/Laravel: quản lý người dùng, phân quyền và thiết lập website; hỗ trợ đa ngôn ngữ và khai báo Resource/Settings để giảm viết lại giao diện `.sao`.

**Alpha 0.1.0-alpha.1:** đã có shell responsive, Page/Resource renderer chỉ đọc, auth/Gate, lang DB, dictionary/JSON preview-draft-publish, install/publish/doctor và Settings schema/DB/API và form dùng chung có revision. [Danh sách user](docs/USERS.md) lấy model của guard host, scope/Policy, tìm kiếm/phân trang và dùng chung renderer. Chưa có mutation User/role, UI dịch/AI; phạm vi alpha xem [RELEASE](RELEASE.md).

**Source đang phát triển (chưa phát hành):** thêm [quản lý ngôn ngữ](docs/LANGUAGE_MANAGEMENT.md) tại `/{prefix}/languages`: thêm locale, mặc định UI/fallback/nội dung trong DB, tải JSON tổng và upload preview → draft → publish. Có quyền riêng từng thao tác, session choice và dirty guard. AI chưa triển khai. Tag alpha.1 phía trên giữ nguyên phạm vi cũ.

Source ngày 26/10 bổ sung [Resource CRUD và thùng rác dùng chung](docs/RESOURCES.md): list/search/sort/pagination, thêm/sửa, xóa mềm/khôi phục, bulk nguyên tử và purge opt-in. Project khai model/fields/scope/rules/Policy, không cần viết `.sao` riêng cho màn hình cơ bản. Đã nối User UUID thật của Gehihi; chưa có UI gán role hoặc nâng quyền chung.

Đã có trên [Packagist](https://packagist.org/packages/saola/admin); [kiểm chứng phát hành](docs/RELEASE_VERIFICATION.md).

Quy ước tên: `admin.pages.settings`, `app/bundle.ts`; các bảng mặc định `saola_settings`, `saola_locales`, `saola_translation_*`, `saola_language_batches`, `saola_language_settings`. Prefix DB có thể bỏ/đổi. Xem [NAMING](docs/NAMING.md) về đường nâng cấp an toàn.

Xem [kế hoạch phát triển và đóng gói](docs/ADMIN_PACKAGE_PLAN.md). Gói nối Gate/Policy của host về `saola/permission`, dùng User model của ứng dụng và cung cấp bộ renderer chung; nghiệp vụ chuyên sâu thuộc ứng dụng hoặc package mở rộng.

Admin đã có theme nguồn với manifest/context/CSS riêng; quản lý cài đặt từ xa hoặc marketplace theme nằm ngoài phạm vi này.

Source giao diện nằm trong `resources/saola/admin/themes/{theme}/` với `app/`, `views/`, `assets/` và manifest riêng; logic dùng chung nằm ở `admin/app/`, không còn cây `_shared`. Publisher vẫn nhận diện ownership/đường dẫn cũ để nâng cấp có kiểm tra. Nguồn lang backend nằm riêng ở `lang/{locale}/*.php`, giữ cùng cấu trúc trong package và sau publish. Ứng dụng build cây này bằng toolchain Saola; tài nguyên admin không được copy rải sang các thư mục dùng chung của website.

Lang dùng cây key–value lồng tùy ý → text; file chỉ khai báo catalog/seed, bản dịch runtime nằm trong DB. Người quản trị xuất/nhập một JSON tổng vào DB; AI là bước phát triển tiếp; client tải locale/revision lúc chạy, không cần build lại khi thêm ngôn ngữ. Xem [thiết kế lang động](docs/LANGUAGE_SYSTEM_PLAN.md).

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

CLI export xuất đủ cây, dùng source làm mẫu cho key chưa dịch; UUID baseline nằm ở stderr. Import tạo preview, apply giữ draft và `--publish` mới cập nhật runtime. Giữ source chưa dịch, chuỗi rỗng, key không gửi và chặn conflict/placeholder/duplicate key. UI mới dùng lại `LanguageExchange`; cách thêm ngôn ngữ và đặt mặc định xem [hướng dẫn quản lý](docs/LANGUAGE_MANAGEMENT.md).

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

Source sau alpha.1 có [Settings chia nhóm Website/Hệ thống](docs/SETTINGS.md): thông tin chính, logo/favicon, SEO và thông số vận hành/định dạng dùng chung, không theo lang. Không thêm nhóm liên hệ/social/pháp lý; đã có [thư viện media dùng chung](docs/MEDIA_LIBRARY.md) cho upload, tìm kiếm, metadata, thùng rác/khôi phục và picker ở logo/favicon/SEO; chưa tự áp dụng flag vận hành vào host.

Header source mới dùng dropdown ngôn ngữ động và avatar/tên tài khoản hiện tại; profile/settings/logout nối route host qua `saola-admin.account`. Xem [hợp đồng header](docs/UI_CONTRACT.md#header-tài-khoản-và-ngôn-ngữ--25102026).

[Bộ UI Material](docs/UI_COMPONENTS.md) có 18 component từ template Velzon, nhận data để chọn biến thể; nguồn tại `themes/material/views/components/ui`. Bật `panel.ui_catalog` để xem mẫu ở `/{prefix}/_ui` dưới quyền admin bình thường.

Thương hiệu trên sidebar lấy `general.website_name` theo locale, `branding.logo_dark_url` (fallback `logo_url`) cho nền tối và `branding.favicon_url` khi thu gọn. Chưa có tên thì dùng `panel.brand`/`app.name`; chưa có ảnh thì dùng chữ cái đầu của tên. Theme không gắn logo Saola cố định. Thiết lập được đọc lại ở mỗi request; tải lại trang sau khi lưu để cập nhật cả shell đang cache.

# Cài đặt và nâng cấp source admin

Source hiện tại đã tách giao diện thành `admin/themes/{theme-name}/` trong **gói admin**. [Contract theme, build và đường nâng cấp](THEMES.md) là hướng dẫn hiện hành cho vị trí file, view context và CSS. Các mô tả skin nguyên bản/đường dẫn phẳng bên dưới là lịch sử của theme Classic trước lần tách này.

## Chế độ nguồn gói — P4 chưa phát hành

Với bộ core/client/builder/admin có P4, project mới không cần `admin:install` để chép nguồn frontend. Composer metadata `extra.saola.contexts.admin` trỏ tới `context.manifest.json`; builder tự nhận view, bundle, CSS, kiểu và theme từ gói đã cài. `sao.config.json` chỉ cần `contexts.admin: {}` nếu không đổi các mặc định. Các bước auth/Policy, Resource, migration và sync lang vẫn thực hiện tường minh như bên dưới.

Dùng Vite với plugin `@saolabs/builder/vite` chọn context `admin`. Plugin tự thêm entry/CSS/alias và manifest; chạy `sao-compile admin` trước typecheck, rồi `vite build`. TypeScript có thể extends `resources/js/saola/tsconfig.contexts.json`; giữ các alias context cụ thể nếu project khai lại `compilerOptions.paths`. Nguồn hiệu lực dưới `resources/js/saola/_sources/admin` là output sinh ra, không phải nơi tùy biến.

Project thêm service/provider trong `resources/saola/admin/app/bootstrap.ts`, và override từng view/asset tại cùng đường dẫn tương đối trong `resources/saola/admin/themes/{name}`. Không cần import hoặc spread bundle riêng của gói trong bootstrap mới. Theme project có `theme.manifest.json` được phát hiện tự động. Server chọn theme qua `panel.theme` và chia sẻ lựa chọn theo request; client tải chunk của theme đó trước hydrate. Core mới phân giải CSS qua metadata build, `admin:doctor` nhận diện chế độ không publish.

Host đã có `.publish-state.json` tiếp tục dùng publisher. Muốn chuyển, trước hết đối chiếu bản đã publish và giữ riêng những thay đổi có chủ đích, rồi đặt `contexts.admin.source: "package"`; không xóa customization tự động. `source: "published"` giữ đường cũ. Cần nâng bộ phiên bản đã kiểm cùng nhau; các tag alpha cũ không tự có P4. Nghiệm thu cài từ registry và trình duyệt trên project sạch vẫn thuộc cổng phát hành.

Cập nhật 24/10/2026. Đây là hướng dẫn tích hợp alpha `0.1.0-alpha.1`; phạm vi và các phiên bản đi cùng được ghi ở [RELEASE](../RELEASE.md). Core cần `BundleManifest` hỗ trợ CSS theo context; compiler cần hạ `__()` sang `text()` và giữ props runtime của `@extends`; client cần scoped dictionary, `changeLanguage` và `Router.addNavigationGuard` cho form. Alpha yêu cầu core ^2.4, compiler ^3.0 cho build; client v1.3.0 và builder v1.2.0 được lấy từ GitHub tags đã kiểm, chưa tuyên bố có trên npm registry.

Source sau alpha.1 bổ sung [màn hình Ngôn ngữ và mặc định DB](LANGUAGE_MANAGEMENT.md), chưa có trong tag alpha.1. Khi nâng lên bản có tính năng này cần migrate schema bổ sung, publish/build source và sync nhãn mới; cấu hình quyền riêng theo tài liệu.

Source ngày 26/10 có [Resource list/form/thùng rác](RESOURCES.md). Đăng ký class Resource tại `panel.pages`, model/Policy/scope/migration của host; chạy migration package `create_resource_events`, publish/build và sync lang mới. Store Eloquent mặc định dùng connection mặc định cùng audit. `admin:doctor` kiểm renderer manager, schema/table/SoftDeletes và audit cho Resource đã đăng ký. Thêm Resource không cần chép controller hay view.

Xem [NAMING.md](NAMING.md) cho tên view/client/bảng, prefix tùy chọn và nâng cấp từ source cũ.

## 1. Chuẩn bị host

Host dùng User/guard/login hiện có. Cấu hình Gate `access-admin` hoặc đổi `saola-admin.panel.ability`; chưa có grant mặc định hoặc operator tự tạo. Gate phải ủy quyền về Policy/saola-permission của host. Để dùng settings, khai báo thêm `view-site-settings` và `update-site-settings`, hoặc cấu hình ability khác. Chỉ có quyền vào panel không đồng nghĩa có quyền đọc/ghi settings.

Sau khi Packagist nhận repository/tag, cài `composer require saola/admin:0.1.0-alpha.1` và `composer require --dev saola/compiler:^3.0`. Trước khi được index, dùng Composer VCS repository theo [RELEASE](../RELEASE.md).

Toolchain frontend có thể cài trực tiếp từ các tag GitHub, không cần tài khoản npm:

```bash
npm install https://github.com/saolabs/client/archive/refs/tags/v1.3.0.tar.gz
npm install --save-dev https://github.com/saolabs/builder/archive/refs/tags/v1.2.0.tar.gz
```

Commit lockfile của host sau khi chọn dependency. Không chép source vào sibling khi deploy, không dùng lockfile cũ để khẳng định đã tích hợp code mới. Chạy migration và sync trong môi trường đã chọn có chủ đích; installer không tự ghi DB:

```bash
php artisan vendor:publish --tag=saola-admin-config
php artisan admin:install --dry-run
php artisan admin:install
php artisan migrate
php artisan admin:lang:sync --dry-run
php artisan admin:lang:sync
```

`admin:install` và `admin:publish` cùng dùng publisher, chỉ đồng bộ frontend vào `resources/saola/admin`. Lệnh không sửa auth, User, route host, Vite, dependency, config, Blade document hoặc bảng dữ liệu. `vendor:publish --tag=saola-admin-lang` là tùy chọn riêng cho nguồn override tại `lang/vendor/admin/{locale}`; runtime vẫn đọc DB. Không có tag vendor-publish frontend bỏ qua kiểm hash.

## 2. Một cây nguồn và một pipeline build

```text
vendor/saola/admin/resources/saola/admin/
resources/saola/admin/
  context.manifest.json
  app/bootstrap.ts
  app/bundle.ts
  views/{pages,layouts,components,partials}/
  assets/{css,images}/
  .publish-state.json                  # chỉ có ở host; lưu hash upstream
```

Hai cây có cùng cấu trúc. Root package không chứa `.publish-state.json`. Toàn bộ frontend do admin sở hữu nằm trong root này. Output do toolchain sinh vẫn theo hợp đồng Saola: Blade tại `resources/views/admin`, JS trung gian tại `resources/js/saola`, build deploy tại `public/static/saola/admin`; không đưa file authored vào các thư mục generated đó.

Trong `sao.config.json` giữ các mục tương ứng (merge vào cấu hình hiện có):

```json
{
  "paths": {"saoView": "resources/saola", "bladeView": "resources/views", "compiled": "resources/js/saola", "public": "public/static/saola"},
  "contexts": {
    "admin": {
      "app": ["admin/app"],
      "views": {"admin": "admin/views"},
      "blade": {"admin": "admin"},
      "compiled": {"views": "admin/views", "app": "admin/app", "registry": "admin/registry.js"}
    }
  }
}
```

Nếu host chưa có `app/bootstrap.ts`, bản publish import bundle có sẵn. Nếu host đã có bootstrap, publisher giữ nguyên file và báo integration. Import `./bundle` rồi hợp nhất `services/providers/helpers/boot` với bundle host, dùng cơ chế bundle của client. Không chỉ thêm import bỏ side effects: service `AdminUI` phải được đăng ký. Không ghi đè providers/auth của host. `admin:doctor` chỉ kiểm dấu hiệu import, không chứng minh merge đúng; build và kiểm runtime mới xác nhận được.

Bundle admin import CSS `../assets/css/admin.css`; ảnh SVG dùng URL tương đối để Vite xử lý. Alias `@saolabs/client` phải resolve **một runtime** của host. Build entry `resources/js/saola/app.admin.js` với Vite manifest tại `public/static/saola/admin/manifest.json`, giữ `preserveEntrySignatures: 'exports-only'` theo hợp đồng theme. Không copy runtime vào assets admin. Tránh import CSS web trong bootstrap/shared bundle admin; có thể bỏ input Tailwind `resources/css/app.css` khỏi lượt build context admin.

Provider đặt `sao.assets.css.admin` khi bật panel. `BundleManifest` dùng CSS source admin riêng, hoặc CSS Vite phát sinh từ JS entry của context; web vẫn giữ CSS cũ. Host cần document `_system.page.begin/end`, nối `APP_CONFIGS.i18n` từ request attribute `saola.i18n`, các config/router/bundle và SSR boot như starter hiện tại. Partial `head` của host được giữ; khi thiếu, provider dùng `admin.partials.head`. Publisher không tự sửa document toàn website.

Với scripts của starter hiện tại:

```bash
npm run build:admin
php artisan admin:doctor
```

Chỉ bật `saola-admin.panel.enabled=true` sau khi cấu hình guard/login/Gate, migration, sync và build đã sẵn sàng. Prefix mặc định `/admin`, có thể đổi thành `control`; dùng prefix không trùng route host. Provider chặn collision URI chuẩn, không bảo đảm phân tích mọi regex/catch-all của host. Sau khi đổi cấu hình, cập nhật config/route cache theo quy trình deploy của host. Guest nhận 401 JSON; HTML chuyển login nếu cấu hình `panel.login_route`, nếu không cũng trả 401. Trang không có quyền trả 403, page chưa đăng ký trả 404.

Directory user tắt mặc định. Khi cần bật, làm theo [USERS](USERS.md): read ability và model Policy viewAny, scope host, map cột/key, chạy doctor rồi kiểm list/count/search. Model lấy từ guard đã chọn; package không tự tạo User, cấp role hoặc mở thao tác ghi user.

## 3. Đồng bộ và nâng cấp

```bash
php artisan admin:publish --dry-run
php artisan admin:publish
php artisan admin:doctor
```

Publisher so ba hash: upstream lần trước, file host hiện tại và source package mới. File chưa sửa được cập nhật; file chỉ host sửa được giữ; cả host/package sửa thì dừng cả batch và báo path. File cũ chỉ xóa khi admin sở hữu và hash host chưa đổi. Collision file không thuộc admin hoặc file admin đã bị xóa cục bộ cũng dừng. Bootstrap host là ngoại lệ được giữ và không nhận ownership. File host/theme không được quản lý không bị xóa.

Dùng diff để merge khi conflict; giữ bản tùy chỉnh và lưu snapshot Git trước khi đổi. Khi cần tiếp tục giữ một tùy chỉnh khác upstream, quy trình chấp nhận baseline/merge tương tác còn cần bổ sung; hiện không có `--force` hoặc tự nhận file host. `.publish-state.json` là dữ liệu ownership phải giữ cùng source host, không sửa tay để bỏ kiểm xung đột.

`--dry-run` không tạo file/thư mục. Lượt ghi có lock cục bộ, stage, kiểm lại hash và rollback khi exception; chưa phải transaction chịu được mất điện/kill giữa lượt ghi, cũng không khóa editor khác. Thực hiện trong release/worktree riêng rồi build/deploy đồng bộ. Publisher từ chối symlink ở source/target và các ancestor; với Composer path repository dùng bản copy/canonical path thay vì checkout symlink. Không hỗ trợ source symlink âm thầm trỏ ra ngoài package.

`admin:doctor` kiểm source/state, bootstrap, context, view document/renderer, file JavaScript/CSS build và sự tồn tại của bảng theo cấu hình; khi bật User directory còn kiểm model/scope/các cột identity trên connection của model. Nếu chỉ còn bảng tên cũ, doctor báo cách giữ config cũ để không đọc nhầm DB trống. Nó không cấp quyền, migrate, build hoặc kiểm hết compatibility/backend/DB/theme. Kiểm clean install từ registry, ma trận DB/framework và upgrade dưới production concurrency vẫn là cổng trước phát hành.

## 4. Workbench và bằng chứng

Trong checkout admin độc lập, cài dependency rồi chạy:

```bash
# tại admin/
composer install
npm ci --ignore-scripts
vendor/bin/phpunit -c phpunit.xml
admin_preview_root=$(mktemp -d /tmp/saola-admin-preview.XXXXXX)
SAOLA_ADMIN_PREVIEW_DIR="$admin_preview_root" vendor/bin/phpunit -c phpunit.xml --filter test_ssr_compiles
node workbench/build-preview.mjs "$admin_preview_root"
SAOLA_ADMIN_PREVIEW_DIR="$admin_preview_root" php -S 127.0.0.1:8769 -t "$admin_preview_root/public" workbench/serve-preview.php
# Terminal khác: chỉ ghi dữ liệu test trong SQLite tạm của workbench.
python3 workbench/check-http.py
```

Preview `http://127.0.0.1:8769/control/settings?locale=vi&content_locale=vi` chạy Laravel với SQLite export tạm của test và source admin đã publish/build. Operator giả được gán sẵn **chỉ trong workbench**, không có login production; chỉ bind loopback, không đưa router này lên host/deploy. Không đọc .env/DB của project. Lệnh check-http tạo dữ liệu Settings, locale Nhật và batch xuất/nhập trong SQLite tạm, khôi phục ba mặc định ban đầu; mỗi lượt export phải dùng thư mục tạm mới/rỗng; exporter từ chối trộn source, registry hoặc DB của lượt cũ. Test client còn hydrate HTML SSR và kiểm form/locale/guard/TypeScript; không thay thế kiểm browser/mobile trên host triển khai. Hiện browser use bị tắt trong Work nên chưa kiểm UI trực tiếp lượt mới.

Tests dùng Composer dependencies của chính admin; compiler và host fixture được khai báo/đóng gói để không cần sibling checkout. Workbench build dùng client/builder từ tag GitHub và Vite đã cài. CI kiểm Laravel 12/13, PHP 8.3/8.5, SQLite, source publish/compile/build/HTTP và Composer ZIP giải nén/publish lại. Fixture không thay thế kiểm auth, query scope, theme, mobile hay concurrency MySQL/Postgres của host triển khai.

## Media library — source 25/10/2026

Trang `pages.media` và component `components.media-library` dùng chung cho quản lý/picker. Migrate bảng `saola_media`, chọn filesystem disk và cấp riêng Gate view/upload/update/delete-media; không cấp tự động. Publish/build admin trước khi mở. Logo/favicon/SEO khai báo `media:true` trên field URL, giữ contract URL đã lưu. Xem [Media library](MEDIA_LIBRARY.md) về scope tenant, tệp công khai, CSRF/CAS và thùng rác giữ liên kết.

## Cấu hình tài khoản trên header

Source 25/10 thêm dropdown ngôn ngữ và tài khoản. Sau publish/build và sync nhãn, header tự đọc user của panel guard; avatar/subtitle cùng route hồ sơ/thiết lập tài khoản/đăng xuất ánh xạ tại `saola-admin.account`. Route mặc định null, chỉ hiện khi host cung cấp đúng method/URL; logout yêu cầu POST/DELETE và CSRF. Không tự thay auth của website. Xem [hợp đồng header](UI_CONTRACT.md#header-tài-khoản-và-ngôn-ngữ--25102026).

## Trang chủ và tích hợp project thật

Source sau alpha.1 có `panel.home_page`, mặc định `workspace`. Host đặt key của Page đã đăng ký trong `panel.pages`, ví dụ `overview`; route gốc panel dùng Page đó, vẫn kiểm ability/AuthorizesPage như route trực tiếp. Key không có trả 404; thiếu quyền trả 403. Không cần fork PageController hoặc tạo màn thống kê nghiệp vụ trong package.

Gehihi đã bắt đầu tích hợp trên Laravel 13/PostgreSQL, guard web và model User UUID, với host-owned Policy/scope/login/logout/Page. Xem [tích hợp Gehihi](../../gehihi-app/docs/ADMIN_INTEGRATION.md) và [ranh giới package/project](../../docs/ADMIN_PROJECT_BOUNDARY.md). Đây là source local chưa phát hành: dependency path dùng bản copy để phát triển; phải chuyển sang dist/lock cài độc lập trước production. Settings/lang/media mới chưa làm cho toàn bộ admin nghiệp vụ của host tự động được chuyển đổi.

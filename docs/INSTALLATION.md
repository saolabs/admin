# Cài đặt và nâng cấp source admin

Cập nhật 24/10/2026. Đây là hướng dẫn tích hợp alpha `0.1.0-alpha.1`; phạm vi và các phiên bản đi cùng được ghi ở [RELEASE](../RELEASE.md). Core cần `BundleManifest` hỗ trợ CSS theo context; compiler cần hạ `__()` sang `text()` và giữ props runtime của `@extends`; client cần scoped dictionary, `changeLanguage` và `Router.addNavigationGuard` cho form. Alpha yêu cầu core ^2.4, compiler ^3.0 cho build; client v1.3.0 và builder v1.2.0 được lấy từ GitHub tags đã kiểm, chưa tuyên bố có trên npm registry.

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
  admin.manifest.json
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

Preview `http://127.0.0.1:8769/control/settings?locale=vi&content_locale=vi` chạy Laravel với SQLite export tạm của test và source admin đã publish/build. Operator giả được gán sẵn **chỉ trong workbench**, không có login production; chỉ bind loopback, không đưa router này lên host/deploy. Không đọc .env/DB của project. Lệnh check-http tạo dữ liệu test trong group general của SQLite tạm; mỗi lượt export phải dùng thư mục tạm mới/rỗng; exporter từ chối trộn source, registry hoặc DB của lượt cũ. Test client còn hydrate HTML SSR và kiểm form/locale/guard/TypeScript; không thay thế kiểm browser/mobile trên host triển khai. Hiện browser use bị tắt trong Work nên chưa kiểm UI trực tiếp lượt mới.

Tests dùng Composer dependencies của chính admin; compiler và host fixture được khai báo/đóng gói để không cần sibling checkout. Workbench build dùng client/builder từ tag GitHub và Vite đã cài. CI kiểm Laravel 12/13, PHP 8.3/8.5, SQLite, source publish/compile/build/HTTP và Composer ZIP giải nén/publish lại. Fixture không thay thế kiểm auth, query scope, theme, mobile hay concurrency MySQL/Postgres của host triển khai.

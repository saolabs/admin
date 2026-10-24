# Kế hoạch phát triển và đóng gói saola/admin

Ngày: **24/10/2026**. Trạng thái: **đã có shell/read-only renderer, installer/publisher/doctor, nền lang và Settings schema/DB/API/form; CRUD User/role và editor lang còn theo kế hoạch**. Xem [thiết kế lang](LANGUAGE_SYSTEM_PLAN.md) cho API/CLI và trạng thái tích hợp.

Mục tiêu là một gói quản trị nền tảng: **quản lý người dùng, phân quyền, thiết lập website**, dùng được cho nhiều loại ứng dụng Saola/Laravel. Chức năng chuyên ngành do ứng dụng hoặc package mở rộng cung cấp. Bộ HTML tại `admin-html-template` là nguồn dựng giao diện; `m-ai` và `ai-services` chỉ là nguồn tham khảo nhu cầu/cách sử dụng.

**Phương án chọn:** một Composer package `saola/admin`, tái dùng `saola/core`, `saola/permission` và runtime `@saolabs/client`; Resource/Settings khai báo bằng PHP có kiểu → metadata dữ liệu thuần → bộ renderer `.sao` dùng chung. Theo yêu cầu thống nhất: toàn bộ tài nguyên giao diện admin (views, client code, CSS/JS phụ trợ, ảnh/font/icon và manifest) nằm trong một cây `resources/saola/admin`, cùng cấu trúc ở package và host; nguồn lang backend nằm riêng tại `lang/{locale}/*.php`. `admin:install` đã publish cây frontend đó có kiểm ownership/hash; ứng dụng build bằng toolchain Saola hiện có. Developer thêm chức năng thông thường bằng khai báo field/table/action và code nghiệp vụ; chỉ viết `.sao` khi cần component hoặc trang đặc thù.

Tài liệu này tiếp nối đề xuất admin/CRUD ngày 18/10 trong workspace, thay phần giả định cũ về User và permission bằng hiện trạng ở §2. Bắt đầu triển khai theo §12; cổng quyết định quan trọng nhất là spike đóng gói/SSR/CSR/đa ngôn ngữ ở P0.

## 1. Phạm vi bản đầu

| Nhóm | Bao gồm trong bản 0.x đầu tiên | Ranh giới |
| --- | --- | --- |
| Khung quản trị | Đăng nhập hoặc dùng login có sẵn, đăng xuất, menu/breadcrumb, trang đầu, hồ sơ cơ bản, lựa chọn ngôn ngữ, giao diện responsive | Không tự tạo một hệ tài khoản khác; dashboard chỉ hiện chức năng được phép, không bịa số liệu nghiệp vụ |
| Người dùng | Danh sách/tìm/lọc/phân trang, tạo/sửa các trường cơ bản được ánh xạ, đổi mật khẩu bằng action riêng, gán/gỡ role bằng quyền riêng | Dùng model/guard của host. Khóa truy cập admin có sẵn; khóa toàn bộ tài khoản chỉ bật khi host có adapter thực thi đủ phạm vi (§5) |
| Phân quyền | CRUD role, ma trận module × thao tác, gán role, kiểm quyền menu/page/API/action và Policy bản ghi | Backend/grant dùng `saola/permission`; không tạo engine quyền thứ hai |
| Thiết lập website | Thông tin chung, nhận diện thương hiệu, locale mặc định/các locale đã cài, giá trị nội dung theo locale | Có schema/kiểu/validation/visibility/revision; không sửa `.env` hoặc config thực thi tùy ý |
| Bộ dựng màn hình | Table, form, detail, filter, pagination, action; section/tab; options lookup | Khai báo trong code, không phải trình kéo thả tạo database hoặc chạy code do người quản trị nhập |
| Ngôn ngữ/bản dịch động | DB làm nguồn runtime; key phân cấp, sửa nhóm/key, JSON tổng xuất/nhập, revision/fallback; AI dịch qua adapter | File lang chỉ khai báo/seed; thêm locale hay sửa bản dịch không cần build lại UI; xem kế hoạch lang riêng |
| Theo dõi thay đổi cơ bản | Audit thay đổi User/role/settings, người thực hiện, thời điểm, đối tượng | API/trang danh sách chỉ đọc, có giới hạn lưu; không phải observability hoặc analytics suite |
| Đóng gói | Cài đặt/doctor, migration có chủ đích, source views/client/assets được publish có kiểm soát, translations có override, test app sạch | Không phụ thuộc thư mục sibling hoặc source template ở máy tác giả khi cài package |

Bản đầu không gồm CMS bài viết, commerce, ví/thanh toán, bộ quản trị AI/provider tổng quát, media library đầy đủ, theme marketplace, email campaign, workflow, báo cáo nâng cao, đa tenant/multi-panel hoàn chỉnh hoặc impersonation. Theo yêu cầu cập nhật, quản lý ngôn ngữ/bản dịch trong DB và JSON tổng xuất/nhập thuộc gói nền; AI dịch là job qua adapter, không kéo toàn bộ hệ AI vào admin. Upload nhỏ cho logo là primitive của Settings, không kéo theo file manager.

“Dùng cho mọi loại web” nghĩa là không gắn với nghiệp vụ AI/blog/shop: host vẫn phải có Laravel/Saola và đáp ứng contract tích hợp. Không hứa cài nguyên package PHP vào nền tảng ngoài Laravel. Hỗ trợ DB theo ma trận kiểm thử §13; không phụ thuộc Postgres-only như kế hoạch Agent Store.

## 2. Hiện trạng và bài học từ hệ thống cũ

### 2.1. Những điểm đã đối chiếu trong workspace

| Nguồn | Xác nhận | Hệ quả thiết kế |
| --- | --- | --- |
| `admin/` | Shell/read-only renderer, source install/publish/doctor, lang DB/JSON/dictionary; Settings schema/DB/API/form có revision | CRUD User/role, settings upload/audit, UI dịch/AI còn thiếu; alpha đã có trên Packagist |
| `permission/composer.json`, `permission/README.md`, `src/PermissionServiceProvider.php` | Package quyền riêng đã có: role, grant, middleware, Gate, cache, catalog/route matrix, `canRoute`/`#permission` | Admin cung cấp UI và action quản trị; thao tác cấp quyền đi qua API package để giữ invalidation |
| `core/src/core/Routing/Permission.php` | Enum ACCESS, READ, CREATE, UPDATE, DELETE, RESTORE, FORCE_DELETE, EXTRA; string permission tự do bị từ chối | Không dùng thiết kế capability string `users.update` của đề xuất 18/10 làm storage contract mới |
| `saola/app/Modules/User/Models/User.php`, `saola/docs/USER_MODULE.md` | Starter có một User model trong module, kế thừa core Authenticatable | Default adapter hỗ trợ model starter, không di chuyển User vào package |
| `gehihi-app/app/Modules/Identity/Models/User.php` | Gehihi dùng User khác namespace, UUID, Laravel Authenticatable và `is_admin` | Phải có User adapter và lộ trình chuyển grant; không giả định mọi host dùng một namespace hoặc khóa số |
| `core/src/core/Services/PackageAssets.php`, `BundleManifest.php` | Composer package có `extra.saola.client` theo context; publish chỉ chép file JS khai báo theo basename; nạp trước theme | Chưa đủ cho nguyên cây CSS/font/image/chunk + Blade. Đóng gói admin là bước cần chứng minh, không coi package JS helper là renderer hoàn chỉnh |
| `client/src/core/services/HelperService.ts`, `builder/docs/STANDALONE_DIST.md` | Có `__`, `trans`, `choice`, `setLocale`; build dist hiểu Laravel/package translations. Đổi locale invalidate trang và dựng lại view | Tái dùng bộ dịch; cần nối snapshot DB vào `text()` PHP/client, compiler hạ `__()` trong `.sao` sang `text()` và giữ bản nháp khi đổi locale; Laravel translator giữ nguyên |
| `docs/EXTENSION_ARCHITECTURE.md` | Có vòng đời authored/generated/installed, bundle/import map/theme override | Admin tuân thủ hợp đồng hiện có; không nhét source người dùng vào generated, không bundle runtime lần hai |
| `admin-html-template/html/material/pages-starter.html` | Velzon của Themesbrand, markup Bootstrap; CSS trong checkout ghi Bootstrap 5.3.6; có script demo/plugin và HTML được mirror | Lấy layout/design thành component Saola, không kéo cả demo JS vào SPA |

Các phiên bản tương thích phải chốt theo release thực tế và CI; README cũ không phải nguồn duy nhất để viết constraint. Ví dụ README permission ghi core ^2.1 trong khi composer hiện yêu cầu ^2.2. Không coi package đang có source là đã phát hành dùng được trên registry.

### 2.2. m-ai và ai-services: lấy gì, thay gì

| Điểm tham khảo | Lấy nhu cầu | Cách làm mới |
| --- | --- | --- |
| `json/admin/modules/users/form.json` của cả hai app | Thêm field, nhóm/cột, options và form tương đối nhanh | PHP Field/Section có kiểu, nhãn là translation key; không đưa `call`, `func`, `@change` hoặc HTML tùy ý vào JSON gửi client |
| `json/admin/modules/users/list.json` | Cột/bộ lọc/sắp xếp/action cấu hình được | Column/Filter/Action có contract và renderer đăng ký; query phân trang ở server, không nạp toàn bộ DB vào plugin bảng |
| `m-ai/.../Admin/General/UserController.php` | User có thao tác đặc biệt, options lookup, hooks nghiệp vụ | Giữ action riêng; không mang đại lý, affiliate, ví, số tháng sử dụng, ngân hàng hay reset 2FA vào gói nền |
| `m-ai/.../Admin/General/PermissionRoleController.php` | Giao diện role, ma trận quyền và thành viên | Dùng đúng catalog/grant của `saola/permission`; update role/grant/member có transaction và quyền tách rõ |
| `m-ai/.../Admin/General/SettingController.php` | Thiết lập theo nhóm, form chung và upload | Schema do developer đăng ký; quản trị nhập giá trị. Chưa cho người quản trị tự tạo field có validation/hàm thực thi |
| `ai-services/.../Admin/General/SettingController.php`, `Repositories/Options/SettingRepository.php` | Nhóm thiết lập gọi chung tầng option data | Settings registry + store có type, default, locale và revision; không chép toàn bộ hệ option/group/data cũ |
| `ai-services/.../Repositories/Options/AppSettingRepository.php` | Có biến thể lấy option theo `mini_app`, ref/ref_id | Ghi nhận nhu cầu mở rộng scope sau này; bản đầu chỉ site hiện tại, không tự mang mô hình mini-app/merchant vào admin |

Đường dẫn `...` trong bảng là `app/Http/Controllers` hoặc `app/Repositories` của app tương ứng. Hai app cũ phụ thuộc Gomee khác phiên bản; chỉ xác nhận từ source/JSON trong checkout, không khẳng định đã chạy lại toàn bộ dependency hoặc DB cũ. Không chuyển dữ liệu cũ trong kế hoạch nền này.

## 3. Ranh giới package

| Thành phần | Trách nhiệm |
| --- | --- |
| `saola/core` | Route/context registry, context/view/bundle hooks, primitive dùng chung. Chỉ bổ sung bridge trung lập nếu spike chứng minh thiếu |
| `saola/permission` | Catalog, role/grant/assignment, middleware/Gate và cache quyền. Không chép bảng hoặc trait sang admin |
| **`saola/admin`** | Admin shell, Resource/Settings contracts, renderer, User adapter, UI quản trị User/role/settings, audit và công cụ cài đặt |
| Ứng dụng host | Model/User/guard/broker, nghiệp vụ, Policy, action/service, scope dữ liệu, resources và settings riêng |
| Package chuyên sâu sau này | CMS, commerce, AI, billing... đăng ký Resource/Page/Settings bằng extension API công khai |

**Quản lý theme sẽ là một package riêng, chưa chốt tên.** `saola/admin` chỉ chuẩn bị contract mở rộng và khả năng tương thích giao diện ở §9.3; không phụ thuộc ngược vào package quản lý theme và không đưa màn quản lý theme vào phạm vi bản đầu.

Đợt đầu chỉ phát hành **một Composer package admin**; chưa tách form/table/settings thành nhiều gói hoặc bắt người cài phải cài thêm npm admin. PHP runtime tiếp tục nằm trong vendor; source `.sao`, client module và asset giao diện được publish vào cây resources của host rồi build bằng compiler/builder/client của host. Cài mới hoặc cập nhật frontend cần toolchain Saola tại máy dev/CI; production chỉ nhận artifact đã build, không cần Node lúc phục vụ request.

Mục tiêu ban đầu: PHP 8.3, Laravel 12/13 và các release core/client/permission đã vượt CI. Không ghi constraint quá rộng trước khi test; không ép đồng bộ số version giữa các package. Compiler/builder là công cụ build của package/workbench và host; không cần Node ở production nếu đã build ở CI trước deploy.

## 4. Kiến trúc Resource: giảm viết .sao

Luồng chuẩn:

```text
Module/package đăng ký Resource + Page + Settings
                    ↓
Definition PHP (tĩnh) + Policy/Action (theo request)
                    ↓
DTO schema v1 + dữ liệu đã lọc + capabilities + bản dịch
                    ↓
Renderer .sao chung → Blade SSR / client CSR cùng hợp đồng
```

Resource mô tả một chức năng, không bắt buộc bằng một Eloquent model. Một bảng có thể có nhiều resource; một trang nghiệp vụ có thể tổng hợp nhiều nguồn. Không tự scan mọi model để tạo endpoint quản trị.

### 4.1. Bộ primitive nhỏ cho bản đầu

- **Field:** text, email, password write-only, textarea, number, select, checkbox/toggle, date/datetime; image upload có giới hạn cho branding. Options tĩnh hoặc lookup endpoint đã đăng ký, có phân trang/scope.
- **Column:** text, badge, boolean, datetime, image, link nội bộ; escape nội dung mặc định. Custom renderer phải là component đã đăng ký, không HTML lấy từ DB.
- **Layout:** section, tab, grid responsive, mô tả/hint. Config không chứa class Bootstrap rải khắp module; renderer chuyển token như `columns=2`, `span=1` thành CSS.
- **Filter/Table:** search, select, date range, sort allowlist, page/limit có trần, chọn hàng hiện tại. Filter/sort/page phản ánh trong URL, reload/back giữ đúng trạng thái.
- **Action:** link hoặc mutation, input/confirmation/loading/result; handler PHP + Policy. Bulk nhỏ opt-in, danh sách ID có giới hạn và kiểm từng bản ghi; chưa có chọn mọi bản ghi theo bộ lọc.
- **Page:** trang riêng dùng cùng shell/field/table/action; đây là lối mở cho nghiệp vụ phức tạp, không nhét mọi thứ vào một generic CRUD controller.

Mỗi field khai báo riêng **được xem**, **được ghi**, **bắt buộc** theo ngữ cảnh create/edit. Ẩn/readonly trên UI không phải ranh giới ghi: server bỏ qua hoặc từ chối trường không được phép theo contract thống nhất; trường đặc quyền không được mass-assign chung. Options, count, detail và bulk phải cùng scope với list.

### 4.2. Ví dụ developer thêm một chức năng

Đây là API minh họa cần hoàn thiện ở P2, chưa phải class đang có. Dùng dữ liệu cấu hình PHP có cấu trúc trước; fluent builder chỉ là lớp tiện ích, không phải DSL chứa code tùy ý.

```php
<?php
use Saola\Core\Routing\Permission;

// Phần trả về của ArticleResource::definition() trong module của host.
return [
    'id' => 'content.articles',
    'label_key' => 'admin.content.articles.labels.title',
    'module_path' => ['content', 'articles'],
    'fields' => [
        ['key' => 'title', 'type' => 'text',
            'label_key' => 'admin.content.articles.fields.title',
            'required' => true, 'max_length' => 200],
        ['key' => 'status', 'type' => 'select',
            'label_key' => 'admin.content.articles.fields.status',
            'options' => ['draft', 'published'],
            'option_label_keys' => [
                'draft' => 'admin.content.articles.status.draft',
                'published' => 'admin.content.articles.status.published',
            ]],
    ],
    'columns' => [
        ['key' => 'title', 'type' => 'text',
            'label_key' => 'admin.content.articles.fields.title'],
        ['key' => 'status', 'type' => 'badge',
            'label_key' => 'admin.content.articles.fields.status'],
    ],
    'operations' => [Permission::READ, Permission::CREATE, Permission::UPDATE],
];
```

Host đăng ký resource class qua provider; class có query scope, Policy và Create/Update action hoặc adapter đến service hiện có. Rules FormRequest/action ở backend là nguồn validation cuối cùng; required/max trong schema là UX hint đã được khai báo/kiểm tra nhất quán, không thay nghiệp vụ. Enum chỉ serialize thành string sau kiểm định, rồi ghép với context/path đúng luật permission.

Kết quả mong muốn: thêm resource CRUD thông thường không phải tạo lại file HTML list/form/detail. Custom field/cell chỉ cần viết một component `.sao` rồi dùng lại nhiều resource. Bản đầu không bắt mỗi resource tạo repository/interface/service rỗng nếu đã có query/action đủ rõ.

## 5. Người dùng và xác thực

### 5.1. Một danh tính của host

`config/saola-admin.php` khai báo panel path/name prefix, guard, user model/provider và password broker nếu có. Default lấy model từ auth provider của guard; không hardcode `App\Models\User` hay tạo `Saola\Admin\Models\User`. Model tham gia grant phải đáp ứng `PermissionSubject` và `HasRoles` hoặc adapter tương đương của permission; không bắt kế thừa một User class mới.

`UserAdapter` cung cấp khóa/id type, name/email mappings, query scope, field write allowlist, create/update/password action, khả năng trạng thái/xóa. Default Eloquent adapter hỗ trợ schema name/email/password cơ bản; `admin:doctor` báo rõ khi host thiếu field/contract. Chức năng không được adapter hỗ trợ không tự sinh nút hoặc endpoint ghi.

Bản đầu hỗ trợ khóa số, UUID, ULID theo cấu hình permission; polymorphic actor/subject giữ morph map của host. Không thêm FK trỏ cứng vào bảng users từ bảng admin để ép namespace hoặc loại khóa.

### 5.2. Login, mật khẩu và trạng thái

- Dùng route login của host khi được cấu hình; có màn login tối giản bằng guard/session Laravel khi host chưa có giao diện phù hợp. Login có CSRF/throttle, regenerate session; không tự mở đăng ký tài khoản quản trị công khai.
- Người đã đăng nhập web nhưng thiếu admin ACCESS nhận 403; không bị vòng lặp login và không bị logout khỏi website chỉ vì vào nhầm admin. Logout/đổi mật khẩu theo hành vi guard/session đã công bố.
- Tạo User nhận password qua form write-only, hash bằng cơ chế của host. Đổi password/role là action riêng; password trống khi edit nghĩa là không đổi, không đưa hash/current password ra DTO, audit hoặc bundle.
- UserPolicy bảo vệ tài khoản đặc quyền cả khi sửa email/thông tin khôi phục, không chỉ khi gán role: người chỉ có quyền sửa hồ sơ User không được đổi danh tính đăng nhập hoặc mật khẩu của operator cao quyền hơn để chiếm tài khoản. Các field nhạy cảm có action/quyền và kiểm đối tượng riêng.
- Gửi link đặt lại mật khẩu chỉ bật khi host có broker/notification/mail hoạt động; side effect gửi sau commit. Không gửi mật khẩu thô qua email.
- Gói nền có thể khóa **truy cập quản trị** bằng trạng thái riêng. Nút **khóa toàn tài khoản** chỉ bật nếu `AccountStateAdapter` kiểm được trạng thái ở login và mọi request/session/API liên quan của host; không báo đã khóa tài khoản khi chỉ chặn /admin.
- Xóa User/trash/restore/force-delete mặc định tắt; host bật từng khả năng sau khi có Policy và quy tắc quan hệ. Không đổi toàn bộ model từ hard delete sang soft delete khi cài package. Không trộn Eloquent SoftDeletes với `$deleteMode` của core mà chưa kiểm thử.

Không mang `is_admin`/`type=admin` thành bypass toàn hệ thống. Với Gehihi, chuyển quyền admin hiện có sang role/grant bằng migration/command riêng trước khi bỏ kiểm tra cũ; `is_admin` không tự cấp quyền ở package mới.

## 6. Phân quyền quản trị

### 6.1. Dùng đúng hợp đồng đã có

Danh tính một ô quyền là **context + module_path (mảng segment chính xác) + Permission enum**. Context mặc định `admin`; route có root ACCESS; READ/UPDATE ở module cha không tự cấp quyền module con. Gọi API thuộc khu quản trị vẫn kiểm cùng ô quyền admin của thao tác, không ngầm cấp sang context api.

| Chức năng | Module path đề xuất | Thao tác |
| --- | --- | --- |
| Vào admin | `[]` | ACCESS |
| Xem/tạo/sửa User | `['system', 'users']` | READ / CREATE / UPDATE |
| Gán/gỡ role cho User | `['system', 'users', 'roles']` | READ / UPDATE |
| Đổi mật khẩu User khác | `['system', 'users', 'password']` | UPDATE + Policy riêng |
| Quản lý role | `['system', 'roles']` | READ / CREATE / UPDATE / DELETE |
| Đổi các ô quyền của role | `['system', 'roles', 'grants']` | READ / UPDATE |
| Thiết lập chung/branding/locale | `['settings', '<group>']` | READ / UPDATE |
| Audit | `['system', 'audit']` | READ |
| Locale/bản dịch | `['system', 'languages']`, các submodule translations/imports/exports/ai | READ/CREATE/UPDATE theo chức năng; AI job có quyền riêng |

Các module con đặc quyền được khai báo thật trên registry; không gom hết vào EXTRA vì EXTRA dùng chung cho các action cùng module. Parent ACCESS vẫn là điều kiện nếu đã khai báo. Enum thao tác không được suy ra từ HTTP method/tên nút.

Luồng ghi: authenticate → admin ACCESS/status → route permission → resolve record đúng scope → Policy/field allowlist → validation → action/transaction → DTO/audit. Menu, row capabilities và `canRoute` chỉ phục vụ hiển thị; endpoint luôn kiểm lại. Page-data/lookup/upload/metadata cũng cần kiểm quyền, không chỉ form submit.

### 6.2. Cấp quyền an toàn và phục hồi

- Ma trận lấy catalog/matrix của permission. Label module/operation lấy translation key, DB lưu khóa kỹ thuật; đổi ngôn ngữ không đổi grant.
- Đồng bộ chỉ thêm danh mục, không tự cấp quyền mới hay xóa grant vì module tạm tắt. Sync của admin tái dùng lệnh permission, không viết catalog thứ hai.
- Quyền quản lý role không đồng nghĩa được cấp mọi quyền. `GrantPolicy` kiểm tập quyền có thể ủy quyền (mặc định là giao giữa quyền actor thực có và catalog cho phép); kiểm cả role mới/sửa và User nhận role để tránh nâng quyền vòng qua assignment.
- Có role khởi tạo bằng grants tường minh và ít nhất một operator được cấp quyền quản lý. Không hardcode tên role thành super-admin bypass. Lệnh CLI bootstrap/recovery có dry-run, chọn User hiện hữu hoặc tạo có chủ đích, không mật khẩu mặc định.
- Giao dịch gỡ role/quyền hoặc khóa operator phải khóa một bản ghi bảo vệ chung, tính lại operator còn hiệu lực, không cho hai request đồng thời loại bỏ người quản trị cuối cùng. Chính sách này bảo vệ đường ghi qua package; host sửa thẳng DB phải dùng công cụ phục hồi/đối soát riêng.
- Mutation role/grant/assignment đi qua API permission; kiểm invalidation sau commit, kể cả rollback và Octane nhiều worker. Dữ liệu capabilities không cache tĩnh chung mọi actor. UI xử lý 403 và refresh capabilities khi quyền đổi; snapshot JS có thể cũ, không là nguồn cấp quyền.

## 7. Thiết lập website

### 7.1. Schema do code sở hữu, giá trị do quản trị sở hữu

`SettingsGroup` khai báo id, label key, fields, defaults, type/rules, có bản dịch nội dung hay không, public/private, read/write permission. Module/package đăng ký nhóm mới qua provider; form/tabs/lưu/validation/audit dùng lại renderer. Người quản trị chưa được sửa schema, tên hàm, connection, route hoặc validation của server từ UI.

| Nhóm nền | Giá trị gợi ý | Ghi chú |
| --- | --- | --- |
| `site.general` | site_name, tagline, description, contact_email | name/tagline/description có thể localized; email là cấu hình riêng, không tự public nếu chưa khai báo |
| `site.branding` | logo sáng/tối, favicon, brand color | Lưu asset reference do host/upload adapter quản lý, không lưu base64 vào settings |
| `site.localization` | default_content_locale, enabled_content_locales | Chỉ chọn locale đã có trong catalog; ngôn ngữ nội dung website tách ngôn ngữ giao diện admin |

Chưa có SMTP, API keys, billing config, arbitrary HTML/JS, APP_KEY, APP_DEBUG hoặc tự sửa APP_URL trong bộ nền. Extension cần secrets phải dùng backend secret store/field write-only, không tự được serialize chỉ vì đã đăng ký Setting. Settings là dữ liệu runtime của ứng dụng, không ghi lại `.env` hoặc chạy `config:cache` từ nút Lưu.

### 7.2. Lưu trữ, xung đột và cache

Đề xuất store theo **một document mỗi group** để kiểm định và commit toàn nhóm: `saola_settings(group_key unique, schema_version, revision, values JSON, updated_at)`. Trong values chỉ có key được schema chấp nhận; field localized là map locale → giá trị. Không làm EAV động cho mọi field. Khi ứng dụng đã có settings store, bind `SettingsStore` adapter; không ép import dữ liệu hoặc sửa `app_settings` sẵn có.

Mỗi update gửi `expected_revision`; compare-and-swap trong transaction, 409 khi form cũ, giữ dữ liệu nhập và cho tải/so lại. Migrate schema version bằng code, không tự xóa key của extension vừa tạm tắt. Default áp dụng khi chưa có giá trị; phân biệt bỏ trống, null và reset về default theo field type.

Cache theo group + revision, nếu resolve theo locale thì thêm locale; invalidate sau commit, không giữ actor/locale/value riêng từng request trong singleton của worker. Update transaction và audit cùng connection; nếu adapter settings dùng store ngoài DB, phải công bố cơ chế audit/atomicity khác trước khi bật ghi.

Public reader chỉ xuất allowlist giá trị public theo locale/fallback; không gửi cả bảng settings vào bootstrap. Logo/favicon upload kiểm quyền group, dung lượng/MIME thật, tên/path do server đặt; SVG chưa bật nếu chưa có sanitizer. File upload tạm có vòng đời dọn/attach rõ; lưu DB lỗi không được xóa file đang dùng của phiên bản trước. Đây là upload primitive, không cần package media library ở đợt đầu.

### 7.3. Đối chiếu thiết lập và nội dung đa ngôn ngữ của ai-services

Rà soát bổ sung không chỉ form/list, mà cả luồng tạo nhóm → dựng form → lưu → đọc runtime:

| Source ai-services | Hành vi xác nhận | Yêu cầu cho admin mới |
| --- | --- | --- |
| `app/Repositories/Options/SettingRepository.php::createNewData()` | Nhóm `system`, `mailer`, `favicons`... có `is_mlc=0`, locale null; `siteinfo`, `contents`, `default` tạo theo từng Language, `is_mlc=1` | Phân biệt thiết lập dùng chung và giá trị nội dung theo locale. Không nhân bản toàn bộ cấu hình kỹ thuật khi thêm ngôn ngữ |
| `app/Repositories/Options/OptionRepository.php::getOptionFormData()` | Cùng dữ liệu field sinh inputs và values, gộp props; không trả validate/rules/messages trong inputs | Giữ cách dùng renderer chung, nhưng schema do code quản lý và public DTO có allowlist; không chuyển rule thực thi/hàm PHP xuống client |
| `app/Http/Controllers/Admin/General/SettingController.php::getSettingForm()` | Form nhóm đa ngôn ngữ có hidden `__locale__`, lấy locale từ cấu hình đang có | Editor mới chọn `content_locale` tường minh; server kiểm locale/group/quyền, không lấy UI locale hoặc tin hidden field để quyết định ngôn ngữ ghi |
| `OptionRepository::updateOptionData()` | Cập nhật item theo tên, array chuyển thành JSON string trước lưu | Store mới giữ kiểu theo schema (boolean/number/array/asset reference), không JSON encode hai lần hoặc biến checkbox thiếu thành xóa dữ liệu |
| `app/Web/Options.php::check()` và `updateLocaleOptions()` | Nạp phần không locale rồi cập nhật các nhóm theo locale; có cả option gắn theme và mini-app trong static state | Giữ nhu cầu đọc hiệu quả theo locale, không sao chép static state vào worker. Scope/cache phải rõ; theme/mini-app không ngầm trở thành trạng thái chung của mọi request |
| `json/data/web/siteinfo.json`, `contents.json`, `system.json` | Site name/slogan/meta/contact/logo, nhóm nội dung và thông số kỹ thuật được cấu hình thành field | Gói nền lấy phần thông tin site cần thiết; mailer, scraper, commerce... do extension đăng ký, không bê tất cả nhóm cũ vào bản đầu |

**Contract lưu/đọc đề xuất:** field có `localized` tường minh; trong cùng document có thể có `site_name: {vi: "Tên site", en: "Site name"}` và `contact_email: "contact@example.test"` dùng chung. Chọn schema field thay vì bắt mọi field của nhóm phải có bản dịch. Không tạo schema/rules riêng lặp lại cho từng locale.

Editor có hai lựa chọn độc lập: **UI locale** cho nhãn/thông báo và **content locale** cho giá trị đang sửa. Ví dụ UI tiếng Anh vẫn sửa được tên site tiếng Việt. Đổi UI locale không đổi content locale; đổi content locale có dirty-form guard và request riêng, không ghi đè nội dung locale đang mở ở tab khác. Draft/cache form phân biệt group + content locale; phản hồi request locale cũ không được đổ vào form locale mới.

Payload ghi chỉ gồm field hợp lệ và phần locale được phép sửa, kèm revision của group; server patch trên dữ liệu mới nhất trong transaction. Không thay nguyên map bản dịch bằng payload một locale, không xóa locale khác vì form không gửi chúng. Với checkbox dùng chung gửi boolean tường minh. Bản dịch rỗng, chưa có và reset về kế thừa là ba trạng thái khác nhau.

Editor đọc **giá trị gốc** cùng trạng thái thiếu/kế thừa; public/runtime reader mới áp chuỗi fallback được cấu hình: locale yêu cầu → locale nội dung mặc định của site → default schema phù hợp kiểu. Giá trị rỗng có chủ đích không tự bị thay bằng ngôn ngữ khác; fallback không tự ghi bản dịch vào DB. Editor có thể hiển thị nguồn locale của giá trị kế thừa để người quản trị không nhầm là bản dịch đã lưu.

Catalog **content locales** tách khỏi **UI locales đã được công bố trong DB**. Thêm nội dung tiếng Nhật không buộc admin UI đổi sang Nhật; để chọn UI tiếng Nhật cần bộ dịch DB/snapshot hợp lệ và có thể tải lúc chạy, không cần build lại. Tắt locale không xóa bản dịch hiện có; không cho bỏ default content locale mà chưa chọn default hợp lệ khác.

Package quản lý theme tương lai có thể đăng ký SettingsGroup có namespace riêng và field localized trên cùng contract này. Lựa chọn theme active vẫn do core/theme package sở hữu; không dùng một field `theme_id` của thiết lập chung để tự kích hoạt theme như runtime cũ. Đây là điểm chuẩn bị tương thích, không thêm UI hay quy trình quản lý theme vào admin nền.

## 8. Lang động: key → text, DB là nguồn runtime

**Thiết kế đã được điều chỉnh theo yêu cầu người dùng:** file lang chỉ nạp catalog/key/cấu hình và seed ban đầu vào DB. Không dùng câu gốc làm key. PHP/client đọc bản dịch đã công bố qua DB/cache; thêm ngôn ngữ hoặc sửa nhãn không buộc build lại JS. Chi tiết source schema, JSON tổng, import/AI và cache nằm trong [LANGUAGE_SYSTEM_PLAN.md](LANGUAGE_SYSTEM_PLAN.md).

Trong biểu thức `.sao` dùng `__('admin.identity.auth.login.keep_login')`; compiler chuyển sang `text()` cho cả đầu ra PHP và helper client tương ứng. PHP viết tay và PHP sau biên dịch dùng:

```php
text('admin.identity.auth.login.keep_login');
```

Hàm `__()` của Laravel giữ nguyên: không override/redefine, không đổi translator/loader của host để nối catalog DB. Cú pháp `__()` ở trên chỉ được chuyển đổi trong quá trình biên dịch `.sao`.

JSON là object key–value có thể lồng nhau; value là chuỗi hoặc object con, ví dụ `{"save":"Lưu","auth":{"keep_login":"Duy trì đăng nhập"}}`. Không bắt buộc context/module/group hoặc số cấp cố định; leaf ngay ở root và các nhánh sâu khác nhau đều hợp lệ. Seed/xuất/nhập dùng cùng cấu trúc, metadata nằm riêng. Helper `text()` nhận key đơn như `text('save')` hoặc đường dẫn như `text('auth.keep_login')`; `.sao` có thể viết các lời gọi tương ứng bằng `__()`; key hỗ trợ gạch dưới/gạch nối. Key là định danh ổn định, text là giá trị có thể thay trong DB. Package mới không dùng kiểu key là câu văn hoặc identity namespace `package::group` cho catalog này. Dependency đang dùng Laravel `__()` tiếp tục dùng translator gốc, không đổi key hoặc cơ chế dịch của host.

Luồng sử dụng:

1. Cài/sync nạp các mảng PHP đã đăng ký tại `lang/{locale}/*.php` của project/package vào DB; tên file/package không tự ghép vào key, thêm key thiếu và giữ bản dịch đã tùy chỉnh khi upgrade.
2. Người quản trị tạo locale mới, tải **một JSON tổng** dạng cây key–value lồng tùy ý với leaf là chuỗi, kể cả leaf ngay ở root, gồm mọi key được cấp quyền; thay chuỗi rồi upload, xem preview/xung đột và lưu/công bố vào DB. Locale, params và revision nằm trong DB/màn xuất nhập; key chưa dịch dùng source text làm mẫu và được theo dõi riêng để không tự tính là đã dịch.
3. Có thể chạy AI dịch theo locale/nhánh/key thiếu; validate key/placeholder/format rồi lưu DB, không ghi đè chỉnh sửa tay mới hơn. AI adapter/provider được cấu hình riêng, không tự gọi lúc cài admin.
4. PHP/client `text()` dùng cùng contract DB resolver; `.sao` `__()` được compiler chuyển sang `text()`; SSR cấp dictionary snapshot cho client. Client tải thêm locale/revision qua API, cập nhật cache/catalog trước khi render/chuyển locale. Không trộn settings/private data vào từ điển UI.
5. DB publish tăng revision và invalidation cache sau commit; PHP worker, page-data và client nhận cùng snapshot hợp lệ. Dictionary/runtime revision độc lập với build assets; deploy chỉ cần kiểm contract/catalog tương thích.

Phân biệt UI locale, content locale của Settings và timezone/format của actor. Đổi UI locale không đổi nội dung đang biên tập. Dirty-form guard trước khi re-render; preference, html lang và Accept-Language đồng bộ theo locale hợp lệ. `null`/thiếu dịch khác chuỗi rỗng có chủ đích, fallback không tự trở thành bản dịch đã lưu.

### 8.1. Nền hiện có và phần tích hợp tiếp theo

- `core/src/core/Support/helpers.php::text()` đã nối scoped TextResolver/LanguageStore; compiler đã chuyển bare `__()` trong `.sao` sang `text()` và client có `App.Helper.text()`. Laravel `__()` giữ nguyên; cần triển khai tiếp UI/dictionary endpoint theo quyền.
- Client TranslationService có legacy `groups/json` và nhánh Saola `catalogs` riêng. Helper `installLanguage/loadLanguage` đã cài snapshot lúc chạy, kiểm revision và thay cây để key cũ biến mất; endpoint/scope/dirty guard của UI cần nối tiếp.
- Live boot đã đọc `APP_CONFIGS.i18n`; dùng contract đó cho snapshot DB hiện tại/fallback. `initTranslations()` thay cả messages nên không gọi riêng cho mỗi package rồi làm mất key host/theme. Từ điển mới chỉ có key định danh, không sử dụng nhánh json câu → câu cho catalog admin.
- Resolver/cache Saola phải theo request/revision để worker Octane không giữ locale/snapshot cũ. Kiểm compiler `.sao` `__()` → `text()` và parity `text()` PHP/client bằng cùng fixture, kể cả key động/params; không query DB theo từng label và không can thiệp Laravel translator.

Nền lang đã có test cho project/package → sync DB → PHP text, compiler mapping và client snapshot. P0 đóng gói/giao diện admin vẫn chưa hoàn tất; chưa phát hành package hoặc cài migrations vào DB project thật. Hạng mục quản lý/JSON/AI được triển khai theo các phần P1a–P1c ở §12; theme management vẫn là package riêng.

## 9. Dựng giao diện từ admin-html-template

### 9.1. Chuyển thành thành phần Saola

Lấy `pages-starter.html`, `auth-signin-basic.html`, mẫu form/table/modal và trang profile làm điểm xuất phát. Tách shell sidebar/topbar/footer/breadcrumb, navigation item, button, form field, data table, pagination, dialog và notification. Mỗi phần có `.sao` nguồn dùng cho SSR và CSR; thiết kế token màu/khoảng cách/font/icon/width để đổi nhận diện không sửa từng resource.

Chỉ mang asset cần dùng. Không giữ menu demo, thống kê giả, nút mua template, crypto/ecommerce pages hoặc tải toàn bộ charts/plugins. Không nạp cả Bootstrap/DataTables/List.js để mỗi thư viện cùng nắm dữ liệu bảng. Table của admin lấy dữ liệu server; enhancement dropdown/dialog/tooltip có adapter khởi tạo/dọn theo vòng đời component, không chạy lại `app.js` demo sau mỗi lần chuyển route. [Bootstrap mô tả API instance/dispose của plugin](https://getbootstrap.com/docs/5.3/getting-started/javascript/); cách tích hợp Saola vẫn phải được test riêng.

CSS Bootstrap/Velzon chỉ vào document/context admin, không để reset hoặc utility class làm đổi web frontend. File CSS/font/image theo đường dẫn package riêng và URL revision; không dùng font CDN ngoài mặc định, không kéo font từ stylesheet mirror có URL chưa làm sạch. Theme extension override theo design tokens/components, không dựa vào thứ tự alphabet tên package.

### 9.2. Asset để phân phối

Checkout hiện có dấu vết mirror demo và chưa tìm thấy giấy phép phân phối bộ template tại gốc. Trước publish package phải có inventory source/license của CSS/font/icon/plugin và xác định phần nào được đóng gói lại. Không suy quyền tái phân phối Velzon từ giấy phép MIT của riêng Bootstrap.

Việc này không chặn phát triển contract hoặc dựng bản tích hợp nội bộ bằng template đã cung cấp. Nếu bộ asset không phù hợp để phân phối công khai, giữ skin Velzon ở host/private và phát hành renderer nền bằng asset đã xác định quyền dùng. Không biến toàn bộ admin thành một fork của bộ demo.

### 9.3. Chuẩn bị tương thích theme, quản lý đóng gói riêng

Đây là yêu cầu kiến trúc ngay từ bản đầu, **không phải triển khai chức năng quản lý theme**. Package riêng sau này cung cấp màn danh sách/cài đặt/kích hoạt/cập nhật/gỡ/khôi phục theme và nối với cơ chế theme của core. Admin nền không tạo các bảng, route, menu hoặc grant dành riêng cho những chức năng đó lúc này.

- **Phân tầng rõ:** `.sao` publish vào `resources/saola/admin` là giao diện nền của admin. Theme cài riêng tiếp tục có vòng đời riêng theo core/Extension Architecture; `admin:install` và `admin:publish` không ghi, xóa hoặc nhận sở hữu file trong `themes/`, `resources/views/themes/` hay public assets của theme.
- **View có điểm thay thế:** dùng key logic ổn định và cơ chế resolve context/fallback của core. `admin.*` là key nền mặc định, không hardcode đường render khiến theme không thể thay layout/component. Theme chỉ thay phần cần thiết, phần thiếu vẫn dùng base; SSR và registry client phải resolve cùng kết quả.
- **Giao diện có contract:** công khai design tokens, component props/slots và renderer keys có version. Resource/Settings mô tả chức năng và dữ liệu, không buộc module nghiệp vụ phụ thuộc markup/class của Velzon. Thay skin không đổi validation, Policy hoặc backend action.
- **Context độc lập:** lựa chọn theme web không tự đổi theme admin và ngược lại. Không mặc định mọi theme website có thể dùng làm giao diện admin; package quản lý sau này kiểm context và compatibility. Admin nền luôn có giao diện mặc định để dùng khi chưa chọn theme tương thích.
- **Điểm gắn cho package sau:** dùng registry Resource/Page/Settings/menu và contract quyền sẵn có; không cần sửa shell `.sao` mỗi khi cài thêm package quản lý theme. Core tiếp tục sở hữu cơ chế theme/runtime, package mới sở hữu chức năng quản trị; không dựng theme engine thứ hai trong admin.

P0 chỉ cần fixture kiểm một phần layout/component có thể được override và fallback đúng ở SSR/CSR, cùng kiểm publish/build không đụng theme đã cài. Đây là kiểm khả năng tương thích của nền, không mở thêm hạng mục xây giao diện hoặc workflow quản lý theme. Các khả năng như upload, preview, activate/rollback và chính sách nguồn theme được thiết kế trong kế hoạch của package quản lý theme sau.

## 10. Đóng gói, build và cài đặt

### 10.1. Một cây tài nguyên admin ở cả package và host

**Quyết định cấu trúc (24/10/2026):** tên con dùng `admin` trực tiếp, không thêm tầng `saola_admin`; bảng chỉ có `saola_` nếu cần, có cấu hình bỏ/đổi prefix. Xem [NAMING](NAMING.md) và đường nâng cấp giữ dữ liệu. Giữ `resources/saola/` cho nguồn giao diện ở cả package Laravel và project, không chuyển sang `src/` hoặc rút thành `resources/admin/`. `src/` trong package tiếp tục chứa PHP. Các đề xuất đổi root trong trao đổi trước không áp dụng; triển khai admin không kèm migration thư mục nguồn.

**Ràng buộc:** toàn bộ source frontend/tài nguyên đóng gói của admin nằm trong một root `resources/saola/admin`; nguồn seed ngôn ngữ backend nằm riêng trong `lang/{locale}/*.php`. Cấu trúc bên trong package và sau publish giống nhau, copy không cần viết lại import/URL trong từng file. Không tách source CSS, font, ảnh hoặc JS phụ trợ admin sang các thư mục tài nguyên chung của host. Các đường dẫn tài nguyên rời ở thiết kế trước được thay bằng cấu trúc này.

```text
saola/admin/                              # repository/package Composer
  src/, config/, database/                # backend của package, ở vendor khi cài
  lang/{en,vi}/{admin,common}.php         # nguồn seed backend; key từ mảng, không từ tên file
  resources/saola/admin/                  # root tài nguyên frontend duy nhất
    app/
      bootstrap.ts                       # blueprint tích hợp cho context admin
      bundle.ts                          # services/bundle của context
      settings-form.ts                   # state/request cho form
    views/
      layouts/, pages/, components/, partials/ # gọi admin.pages.settings, không lặp package
      custom/                            # điểm mở rộng do host sở hữu
    assets/
      css/admin.css                      # entry CSS riêng của admin
      js/                                # JS phụ trợ của giao diện
      images/, fonts/, icons/, vendor/   # chỉ asset thực sự cần dùng
    admin.manifest.json                  # roots tương đối, version, UI contract

host/resources/saola/admin/               # cùng cây app/views/assets
  ...
  .publish-state.json                    # hash nguồn đã cài; đưa vào Git của host
```

`src/config/database` là phần PHP của package trong vendor, không scaffold vào các module/backend của host. Tài nguyên frontend ở host chỉ có root admin nêu trên; manifest không là nguồn settings/người dùng và không chứa secret. Catalog/seed và manifest không được copy ra public assets; dictionary public được xuất từ snapshot DB qua endpoint có scope.

Provider đăng ký context admin khi panel bật; installer hiện chỉ publish frontend và báo điểm tích hợp, không tự sửa sao.config/Vite/bootstrap host; view namespace hiện có như `admin.*` vẫn giữ ổn định. `custom/` và file host tự thêm không tự thuộc danh sách package quản lý. Nối bootstrap/config Vite chỉ là các điểm tích hợp tối thiểu vào công cụ chung, không là nơi chứa một bản sao code hoặc asset admin. Backend configuration/migration vẫn theo cơ chế Laravel có chủ đích (§10.3), không đổi đường auth/User của host.

Host compile `.sao` thành Blade và JS trong cùng lượt build, sinh registry rồi Vite build context admin. Đầu ra là `resources/views/admin`, `resources/js/saola/admin` (entry do builder sinh theo cấu hình hiện có) và `public/static/saola/admin` theo chuẩn generated của Saola. Đây là artifact có thể tái tạo; không đặt đầu ra vào root source để tránh watcher quét lại chính kết quả build. Source/theme/customization không bị clean cùng output.

Package dùng workbench/build/test với chính cây source trên, không có bản HTML/CSS viết tay thứ hai để chép riêng khi release. Release chứa đủ resources/manifest và kiểm được sau đóng archive không còn sibling repo. PHP API/renderer DTO, source đã publish và artifact build có version/contract đối chiếu; doctor/build phát hiện lệch trước deploy.

### 10.2. Những việc installer cần nối đúng

- **View:** publish source `.sao`, không publish riêng Blade rồi giữ JS từ bản khác. Không chỉnh generated Blade/JS; không nạp thêm bộ renderer prebuilt từ vendor trong chế độ này. App extensions dùng cây custom hoặc component map tường minh.
- **Client bootstrap:** builder hiện chỉ tự nạp bootstrap chung và bootstrap đầu tiên của context. Copy `app/bundle.ts` là chưa đủ: cần import/merge bundle vào `admin/app/bootstrap.ts`. Host chưa có file thì scaffold; đã có thì đưa patch có thể review, không ghi đè hoặc nối chuỗi lặp. `@saolabs/client` dùng một runtime của host.
- **CSS/assets:** entry lấy từ `resources/saola/admin/assets/css/admin.css`; Vite và layout admin đọc entry/URL từ contract context/manifest, không copy sang entry CSS chung. Mỗi asset có đường xử lý rõ: import/bundle hoặc static copy có danh sách sở hữu; URL cuối dùng asset base/manifest, không hardcode đường tuyệt đối hoặc domain của project mẫu. Font/icon/vendor dependency cần cho giao diện nằm trong assets admin, không mặc định lấy CDN hoặc file của web context.
- **Config/translations:** publish config backend có chọn lọc; nguồn lang nằm riêng ở `package/lang/{locale}/*.php`, đăng ký với registry rồi sync DB có chủ đích; tùy chọn publish override vào `lang/vendor/admin/{locale}`, không ghép vendor/package/file vào key. PHP `text()` resolver và `APP_CONFIGS.i18n`/dictionary endpoint cùng đọc DB snapshot, không coi file seed là bản dịch runtime. Sync không đè bản dịch DB đã sửa và không nạp lại source trên mọi request. Không copy auth config/User model hoặc nướng dữ liệu cá nhân vào bundle.
- **Xung đột:** phát hiện route /admin, file cùng đích, thiếu script build/context và version compiler/client không phù hợp. Dry-run liệt kê thay đổi cần làm; không tự đổi prefix hoặc cấu hình host mà không báo.

Frontend hiện chỉ qua `admin:install`/`admin:publish` để không bỏ kiểm ownership/hash bằng một tag copy trực tiếp. Tag Laravel hiện có là `saola-admin-config` và `saola-admin-lang`; không có `saola-admin-source`. Bootstrap host có sẵn được giữ và báo cần merge, chưa tự tạo patch. Không dùng `vendor:publish --force` như quy trình nâng cấp frontend. Chế độ prebuilt không cần host build có thể khảo sát sau, không làm đồng thời hai đường render trong MVP.

### 10.2.1. Đồng bộ source, build và runtime

Đối chiếu `saola/vite.config.js`: đã có `saolaAssets` đồng bộ `resources/saola/<context>/assets` sang public cùng context; dev có watcher, build copy sau khi Vite dọn/ghi output. Tuy nhiên bản hiện tại chỉ cảnh báo khi copy lỗi và không xóa file đã mất khỏi source; entry CSS còn trỏ tới tài nguyên chung. Vì vậy đây là nền để hoàn thiện tích hợp, chưa phải bằng chứng asset admin đã độc lập và đồng bộ đầy đủ.

- Build tích hợp đọc root/entry của admin qua context manifest hoặc hook trung lập của builder/core; không hardcode vị trí riêng của Gehihi. Nếu BundleManifest hiện chỉ đọc CSS entry chung thì phải bổ sung khả năng chọn CSS theo context, không tạo bản sao source chung để né thiếu hook.
- Dev watch root frontend admin đúng phần app/views/assets; asset thay đổi cập nhật đúng context, sửa nguồn PHP lang bên ngoài frontend chỉ sync DB qua lệnh chủ đích, không âm thầm đè bản dịch; sửa giá trị dịch trong DB làm mới runtime revision, không rebuild assets. Không nhân watcher. Cấu hình global chỉ đăng ký hook một lần; các file module/frontend vẫn ở trong admin.
- Release build thất bại khi thiếu asset bắt buộc, lỗi copy/compile, import ngoài contract hoặc revision không khớp. File public cũ chỉ được dọn trong tập output do admin sở hữu; không xóa file host/theme. Assets import và static copy không được ghi đè nhau theo thứ tự ngẫu nhiên.
- CSS admin có trong HTML lần đầu; Blade/JS registry/assets dùng cùng build. Từ điển DB có revision độc lập nhưng phải đáp ứng catalog/key/format mà UI dùng; SSR HTML và initial dictionary cùng snapshot published. Cập nhật DB translation không cần build, deploy source mới phải sync catalog trước khi mở chức năng dùng key mới.
- `public/static/saola/admin` chỉ chứa đầu ra browser được phép đọc. Upload người dùng và dữ liệu runtime tiếp tục thuộc storage/DB của host; không trộn vào source/assets để publish/update xóa nhầm. Theme có vòng đời/đường dẫn riêng như §9.3.
- Chứng minh tính độc lập bằng host sạch chỉ có dependencies khai báo: không cần thư mục mẫu, ảnh/font chung của website, cấu hình hardcode của tác giả hay runtime client thứ hai. Package mở rộng nối qua registry/contract, không chép phụ thuộc ngầm vào root tài nguyên chung.

### 10.3. Luồng cài đặt mục tiêu

`admin:install`, `admin:publish`, `admin:doctor` và lang CLI đã có. Quy trình hiện tại theo [INSTALLATION.md](INSTALLATION.md); host tự nối toolchain/guard/Gate. `admin:bootstrap-user` trong luồng mục tiêu bên dưới **chưa triển khai**, chưa dùng luồng đầy đủ này như hướng dẫn thực thi:

```text
composer require saola/admin:<version-đã-phát-hành>
php artisan admin:install --dry-run
# Chọn guard/model/key type, prefix, store; review source/config/bootstrap/CSS.
php artisan admin:install
# Publish source/config/migration cần thiết; chưa tự migrate DB đang dùng.
php artisan migrate
php artisan admin:lang:sync --dry-run
php artisan admin:lang:sync
php artisan saola:permission:sync --context=admin --dry-run
php artisan saola:permission:sync --context=admin
php artisan saola:assets
npm run build:admin
php artisan admin:bootstrap-user --dry-run
php artisan admin:bootstrap-user
php artisan admin:doctor
```

Cài đặt không tự chạy mutation khi Composer provider boot; không publish đè auth/User/view/config, không tạo tài khoản/mật khẩu mặc định và không cấp mọi quyền mới. Install chạy lại không nhân file/bootstrap import/migration/resource/role. Provider đăng ký bindings/routes/config và catalog lang qua registry Saola; Laravel translator giữ nguyên; route phải cache được. `saola:assets` tiếp tục phục vụ client helper của permission và package khác, không thay bước compile source admin.

Source đã publish và manifest trạng thái đưa vào Git cùng host. Deploy nhận artifact build và PHP/package version tương ứng; production không cần tự compile giao diện từ vendor lúc request đến. Để sửa nhãn/ngôn ngữ ưu tiên translation/config, để thêm chức năng ưu tiên Resource/Settings, không phải sửa source renderer cho mọi module.

### 10.4. Nâng cấp giữ được tùy chỉnh

`admin:publish --dry-run` so sánh ba trạng thái: hash source package lần publish trước, file hiện tại của host và source package mới. `resources/saola/admin/.publish-state.json` lưu version nguồn/hash từng file; `admin.manifest.json` mô tả contract/tài nguyên của bản phát hành. Không lấy mỗi version Composer làm bằng chứng frontend đã cập nhật. View/client/assets thuộc batch publish frontend; nguồn lang backend nâng theo Composer và sync riêng, override source tùy chọn publish bằng tag lang riêng; bản dịch DB giữ vòng đời dữ liệu riêng, file state do installer quản lý, không bị ghi đè bằng bản manifest source.

| Trạng thái file | Hành vi mặc định |
| --- | --- |
| File mới, đích chưa tồn tại | Thêm mới |
| Host còn đúng bản đã publish trước | Cập nhật khi package đổi |
| Host đã sửa, package không đổi | Giữ bản host |
| Host và package đều đổi, nội dung khác nhau | Báo xung đột/diff, giữ file host; developer merge tường minh |
| Package bỏ file | Chỉ xóa file không bị host sửa và có trong manifest sở hữu; file đã sửa giữ lại và báo obsolete |
| Host tự xóa file từng được publish | Báo thay đổi cục bộ, không tự khôi phục hoặc mặc định coi là cài thiếu |
| File đích có sẵn nhưng không thuộc manifest | Collision, không nhận sở hữu/ghi đè theo phỏng đoán |

Bản đầu chỉ cần phát hiện/hiển thị diff và cho merge bằng Git; chưa cần engine tự merge `.sao`. Có xung đột thì mặc định dừng trước khi ghi cả batch, giữ manifest cũ; kiểm source/target hash lại trước áp dụng, có staging/backup để phục hồi khi ghi lỗi. Sửa file/manifest xong phải compile và kiểm UI contract trước deploy; không coi publish thành công là đã build thành công.

PHP package có thể đã cập nhật trong khi source host còn cũ: `admin:doctor` cảnh báo hoặc chặn contract không tương thích, không tự force-copy để chữa. Quy trình: cập nhật Composer → publish dry-run → review/merge → publish → build/test → deploy đồng bộ. Code rollback không tự rollback dữ liệu/migration.

## 11. API, dữ liệu và extension contracts

### 11.1. Những điểm mở rộng được công khai

Provider của host/package đăng ký `Resource`, `Page`, `SettingsGroup`, `FieldRenderer`, `ColumnRenderer` và `Action` qua registry; định danh có namespace ổn định. Trùng key phải fail hoặc override tường minh, không “package nạp sau thắng” với chức năng đặc quyền. Registry chỉ giữ definition tĩnh; query/Policy/capabilities resolve theo request.

Host truyền handler/action class, không gửi tên service/container class hoặc closures thực thi trong metadata client. Schema có version độc lập với version package; thay đổi breaking tăng major contract/compatibility. Không cho DB settings tải JS hoặc provider PHP mới vào runtime.

### 11.2. HTTP và mutation

Prefix mặc định `/admin`, route name `saola.admin.*`; data/mutation dưới `/admin/_api` và cùng session/CSRF/admin middleware. Endpoint options/metadata/upload/action phải thuộc resource đăng ký và có kiểm quyền tương ứng; request không chọn arbitrary model/class/method/SQL field.

List/query/search/sort dùng allowlist và giới hạn độ dài/page size; server trả rows đã lọc, pagination và capabilities. Metadata schema không bao gồm password hash, rules PHP đầy đủ, prompt/secret cấu hình hoặc giá trị field không được đọc. Capability per-row là UX hint, không bỏ lần kiểm Policy lúc ghi.

Status thống nhất: 401 chưa đăng nhập JSON, 403 thiếu quyền, 404 đối tượng không có/ngoài phạm vi theo quy ước host, 422 lỗi field, 409 revision/trạng thái xung đột. HTTP mã thật, không mọi lỗi đều 200 + success=false. Client giữ form khi 422/409/mất mạng, chống response cũ ghi đè trang mới, disable submit đang chạy; action dễ nhân bản cần request id/receipt phù hợp. Side effects ngoài DB thực hiện sau commit, có cơ chế retry riêng nếu quan trọng.

User/role/resource sửa đồng thời nhận token phiên bản từ lần đọc và so lại dưới khóa khi ghi. Adapter dùng version native hoặc timestamp đủ độ phân giải đã kiểm chứng; không tự thêm cột version vào users của mọi host. Nếu adapter không chứng minh được optimistic concurrency thì không công bố bảo đảm chống ghi đè, phải chọn chiến lược revision trước khi bật edit qua generic renderer.

Trang API quản trị là giao diện quản trị web trong bản đầu. Nghiệp vụ/action có thể được host tái dùng ở API/mobile khác, nhưng route/API đó phải khai báo auth/quyền riêng; không có chuyện grant admin tự mở toàn bộ API của host.

### 11.3. Schema thuộc admin

| Bảng/projection | Nội dung | Không thay thế |
| --- | --- | --- |
| `saola_settings` | Document group + schema_version/revision/values | SettingsStore sẵn có của host nếu đã chọn adapter |
| `saola_preferences` | Actor morph/key, UI locale/timezone, tùy chọn hiển thị; unique actor + panel key | Không sửa cột User để lưu sở thích giao diện |
| `saola_access_states` | Khóa truy cập admin theo actor, khi dùng default access adapter | Không giả là trạng thái khóa toàn tài khoản của host |
| `saola_audit_entries` | ID sự kiện, actor/target type+opaque key, action code, changed keys/value đã lọc, thời điểm, correlation ID | Không lưu password, token, session, secret hoặc request dump |
| `saola_security_state` | Bản ghi khóa chung cho thao tác ảnh hưởng operator cuối cùng | Không phải bảng grant/role mới |

`roles/permissions/permission_role/role_assignments` do permission sở hữu; `users` do host sở hữu. Tên bảng/connection có cấu hình và được kiểm trước migration. Audit chỉ append qua API package; có retention/purge có chủ đích, không audit thay đổi giả khi transaction rollback. Bản đầu thao tác liên quan User/grant/settings dùng cùng connection để bảo đảm transaction; adapter nhiều connection chỉ bật sau khi có contract đối soát phù hợp.

## 12. Lộ trình triển khai

Mốc thực tế 24/10: P0/P1 đã chứng minh source publish → compile → SSR/hydrate/CSR → đổi locale, CSS theo context và archive giải nén/publish lại; đã kiểm cài từ ZIP độc lập với dependency Packagist trên Laravel 12/13; mobile/theme còn thiếu. P1a/b có backend/CLI/dictionary/guard, chưa editor/upload/preference. P2 có read-only DTO/renderer/Page registry và UserDirectory adapter đọc model host với scope/Policy/search/sort/pagination; mutation User/Resource còn thiếu. P4 đã bắt đầu registry/schema/default general, DB document/CAS revision, content locale độc lập, public projection, API Gate và form metadata có dirty guard/lifecycle; upload/audit/cache còn thiếu. Xem [UI_CONTRACT](UI_CONTRACT.md), [SETTINGS](SETTINGS.md), [inventory](ASSET_INVENTORY.md). Các hàng dưới là đích nghiệm thu, không là xác nhận toàn giai đoạn đã hoàn tất.


| Giai đoạn | Kết quả review được | Xong khi |
| --- | --- | --- |
| **P0. Spike đóng gói và hợp đồng** | Package/workbench dùng cùng cây admin app/views/assets và nguồn lang/{locale} riêng; một màn field/table, một endpoint có quyền, hai locale; manifest publish/build; adapter User | Archive publish một root rồi host build không sibling; CSS entry theo context, SSR/hydrate/CSR và font/ảnh chạy; không runtime kép hoặc resource rải ngoài admin; đổi locale không mất form |
| **P1. Shell, auth và lang DB** | Shell/host guard/preferences; DB locales/keys/values, seed sync, PHP/client `text()`, compiler `.sao` `__()` → `text()` và snapshot vi/en | Key đơn ở root và path lồng tùy ý lookup SSR/CSR cùng DB revision, không text-to-text; user/locale/actor không lẫn state |
| **P1a. Quản lý lang và JSON tổng** | Editor locale/cây key; export file đầy đủ; import preview/validate/commit/publish | Thêm ja từ file JSON dùng ngay PHP/client không build; giữ params, revision và bản dịch locale khác |
| **P1b. Client lang động** | Dictionary endpoint, load/replace snapshot, revision/cache/preference/dirty guard | Locale chưa nạp tải lúc chạy; sửa DB/refetch có hiệu lực, không mất form hoặc key host/theme |
| **P1c. AI dịch** | TranslationEngine adapter/job, chọn scope/source/target, ngân sách, progress/retry/cancel và conflict | Kết quả hợp lệ lưu DB; AI không đè sửa tay mới hơn, lỗi không bị báo hoàn tất |
| **P2. Resource renderer và User** | Field/table/detail/filter/action, query/validation/Policy contracts, User adapter/create/update/password; scaffold resource | CRUD User có scope/field allowlist và lỗi rõ; resource thứ hai trong workbench (Category) chỉ thêm definition/action/Policy, không copy list/form .sao |
| **P3. Quản trị quyền** | Role UI/matrix, grants/assignments riêng, CLI bootstrap/recovery, bảo vệ operator cuối cùng, audit | Không tự nâng quyền bằng role/assignment; đổi quyền có hiệu lực; transaction rollback/cache/concurrency đúng |
| **P4. Thiết lập website** | General/branding/localization, SettingsStore, schema/default/public projection, revision, upload nhỏ | Thêm nhóm settings bằng code không thêm HTML; nội dung vi/en không lẫn UI locale; stale update 409, cache sau commit đúng |
| **P5. Đóng gói phát hành** | CI/archive độc lập, install/publish/doctor, upgrade hash/diff, extension demo, inventory asset, compatibility matrix | Host sạch cài bằng package đã đóng gói; reinstall/update giữ dữ liệu/customization; toàn bộ cổng §13 đạt |
| **P6. Tích hợp Gehihi** | Thay từng phần admin User/role/settings bằng package; đăng ký Agents/Providers/Limits qua Resource/Page riêng | Quyền cũ chuyển rõ; không mất cấu hình provider, quota hoặc prompt hidden; Agent Store tận dụng nền admin đã ổn định |

P0–P5 là phạm vi gói nền đầu tiên. Không ước lượng số ngày khi chưa đo spike P0 và chất lượng asset; không đánh đồng chuyển template HTML với hoàn thiện auth/permission/packaging. Có thể làm riêng phần runtime agent miễn phí của Gehihi trong lúc phát triển admin; trước khi dựng hàng loạt màn quản trị Agents nên có Resource/Settings contracts đã chốt ở P2/P4.

## 13. Cổng nghiệm thu

| Nhóm/tình huống | Kết quả bắt buộc |
| --- | --- |
| Host starter User khóa số và host khác User UUID/namespace khác | Cùng package cài được qua cấu hình/adapter; không tạo User model/table thứ hai |
| Người dùng thường, viewer, operator có quyền ghi | Menu và endpoint kiểm đúng; gọi HTTP trực tiếp không vượt quyền dù sửa JS |
| Role/grant/assignment và quyền module con | Đúng context/path/enum, không wildcard tự phát; không gán role để vòng qua GrantPolicy |
| User editor thử đổi email/password của operator cao quyền hơn | UserPolicy chặn đường chiếm tài khoản, kể cả khi actor có quyền sửa hồ sơ User thông thường |
| Hai request đồng thời gỡ/khóa operator cuối cùng | Chỉ giao dịch hợp lệ được commit; vẫn có operator quản trị hữu hiệu |
| Đổi quyền, rollback, nhiều worker | Cache đúng sau commit; 403 từ server thắng snapshot UI cũ; không giữ actor trong singleton |
| Resource User và Category mẫu | Thêm CRUD cơ bản không tạo lại .sao list/form; custom action có Policy, không phá schema renderer |
| Scope/field permission | List/count/detail/options/metadata/upload/bulk cùng phạm vi; không lộ giá trị hoặc ghi field ẩn qua payload |
| Form 422/409/mất mạng/chuyển trang nhanh | Giữ input an toàn, chỉ lỗi đúng field; response cũ không ghi đè view hiện tại |
| Locale vi/en, namespace host override, thiếu key/plural/params | SSR/CSR giống nhau; fallback xác định; nhãn không buộc fork HTML |
| Đổi locale khi đang sửa form | Dirty guard hoạt động; không mất dữ liệu âm thầm khi view được dựng lại |
| UI locale khác content locale; hai request Octane khác người | Không đổi nhầm nội dung/website default; không rò locale, capabilities hay settings theo actor |
| UI en sửa settings vi; chuyển locale lúc request cũ chưa về | Chỉ locale nội dung đã chọn được ghi/hiển thị; không mất bản dịch en hoặc giá trị dùng chung, không lẫn phản hồi hai locale |
| Settings vi chưa có, rỗng có chủ đích hoặc đang dùng fallback | Editor phân biệt đúng; reader áp fallback theo schema, không tự lưu bản dịch giả; tắt locale không xóa dữ liệu |
| Locale mới trong DB chưa có trên browser; extension/theme bổ sung key | Tải snapshot lúc chạy không rebuild; không xóa key host/admin; seed sync không đè bản dịch đã sửa; reload/preference đúng |
| JSON tổng và AI translation | Export đủ cây key, giữ root leaf và nhánh sâu khác nhau; import idempotent có preview/revision; thiếu params hoặc key lạ bị chặn; AI lưu DB và không đè sửa tay trong lúc job chạy |
| Thiết lập lưu hai tab, upload lỗi sau DB rollback | Revision 409; file đang dùng giữ nguyên; orphan tạm có cơ chế dọn; public reader không lộ private settings |
| Lock User qua default adapter | Chặn admin ở request kế tiếp; không tuyên bố khóa toàn website khi host chưa tích hợp AccountStateAdapter |
| Archive cài host sạch, publish source/build tại dev hoặc CI; deploy production không Node/sibling | Source và manifest đầy đủ; Blade/JS từ cùng lượt build hợp marker contract; CSS/font/icon/chunks không 404; web context không bị CSS admin tác động |
| Phạm vi publish và tài nguyên độc lập | Source frontend/manifest chỉ trong root admin, lang backend theo lang/{locale}; cài lại không tạo bản CSS/font/JS/lang rải trong resources chung; build web không kéo CSS admin, build admin không cần assets của web |
| Đồng bộ thêm/sửa/xóa assets và lang ở dev/release | Đúng URL và revision; thiếu file/copy lỗi làm release fail; không sót output cũ do admin sở hữu hoặc xóa file host/theme; locale SSR/CSR dùng cùng dictionary |
| SPA qua lại list/form/dialog nhiều lần | Không nhân listener/modal overlay/plugin instance; focus/keyboard và back/forward đúng |
| Build và nâng cấp | Source publish có manifest; file không sửa được cập nhật, file sửa được giữ/báo diff, collision/xóa cục bộ không bị mất; source/backend contract tương thích; cài lại không nhân import/migrations/grants |
| Audit | Thay đổi thành công truy vết được; rollback không ghi thành công; không password/token/secret trong audit/HTML/JSON |
| Ma trận DB/framework | SQLite cho unit/feature và cài mới; MySQL/Postgres cho integration/migration/transaction thực; Laravel 12/13 và PHP tương ứng trong CI trước công bố hỗ trợ |

Concurrency/lock phải kiểm trên nhiều kết nối DB thực, không chỉ SQLite in-memory/mock. Browser test dùng app workbench thật cho SSR/hydration/mobile/i18n; test package dưới archive để bắt thiếu source/assets hoặc đường dẫn local. Với thay đổi primitive core/client/permission, chạy thêm kiểm tra hồi quy của package bị tác động.

## 14. Mặc định và giới hạn cần ghi rõ

| Quyết định | Mặc định đề xuất |
| --- | --- |
| Tên/repository | `saola/admin`, source tại `saola-ecosystem/admin` |
| Dạng phát hành | Một Composer package; source frontend app/views/assets/manifest cùng root resources/saola/admin ở package và host; seed backend ở lang/{locale}/*.php; host build; update manifest/hash/diff, chưa cần npm admin riêng |
| User/auth | Model/guard/broker host; adapter chuẩn Eloquent, không hardcode namespace/is_admin |
| Phân quyền | Dùng saola/permission; Resource khai scope/enum; Policy/action chịu trách nhiệm bản ghi/trường và ủy quyền |
| Khai báo màn hình | PHP có cấu trúc/kiểu + DTO versioned; .sao chỉ cho renderer/trang đặc thù |
| Thiết lập | Registry schema trong code + document theo group/revision; host store có adapter |
| Đa ngôn ngữ | Key–value lồng tùy ý, không bắt buộc context/module/group; file chỉ catalog/seed; DB là nguồn runtime; JSON tổng/AI lưu DB, client tải locale mới không rebuild; UI/content locale độc lập |
| Template | Chuyển phần cần thiết từ bộ HTML đã cung cấp; inventory/phạm vi phân phối asset là điều kiện publish |
| Tương thích theme | Chuẩn bị key/slot/token/registry và phân tách vòng đời từ bản đầu; chức năng quản lý theme thuộc package riêng sau này |
| Người dùng bị khóa/xóa | Khóa admin mặc định; khóa toàn account/xóa/restore chỉ khi host adapter/Policy hỗ trợ |
| Phần mở rộng | Chưa làm CMS/shop/AI/payment/multi-tenant engine trong gói nền; chứng minh API mở rộng bằng resource và settings mẫu |

Không cần quyết định mọi package chuyên sâu trước khi bắt đầu P0. Các điểm cần dữ liệu thực lúc triển khai: phiên bản phát hành tương thích, khả năng phân phối asset template, schema/guard cụ thể của host thử nghiệm và chính sách thao tác User. Mặc định nêu trên đủ để dựng spike và hoàn thiện contract, không phải lý do dừng ở phần hỏi lại.

## 15. Tài liệu liên quan và mức độ kiểm chứng

- Đề xuất admin/CRUD trước đây (workspace): giữ bài học form/list/action, thay giả định quyền string bằng enum package hiện tại.
- [saola/permission](https://github.com/saolabs/permission), User module của starter (tài liệu workspace), Extension architecture (workspace), [Standalone dist/i18n](https://github.com/saolabs/builder/blob/v1.2.0/docs/STANDALONE_DIST.md).
- [Thiết kế lang động](LANGUAGE_SYSTEM_PLAN.md): catalog key, DB store, JSON tổng xuất/nhập, AI và đồng bộ PHP/client.
- Kế hoạch Agent Store Gehihi (workspace): consumer của admin Resource/Settings, không đưa nghiệp vụ Agents vào package nền.
- [Laravel package development](https://laravel.com/framework/docs/13.x/packages), [Laravel localization](https://laravel.com/framework/docs/13.x/localization): cơ chế framework để nối config/view/lang/publishing và fallback; các ranh giới Resource/adapter/lộ trình ở đây là đề xuất của Saola.

Đã có nền lang và JSON/dictionary, shell responsive/Page/read-only renderer, source install/publish/doctor, CSS theo context, Settings schema/DB/API/form và [User directory](USERS.md) chỉ đọc có scope/Policy/search/pagination. Workbench kiểm HTTP session/CSRF/stale revision và fresh Laravel boot, SSR source publish thật → client hydrate/CSR/locale; Composer ZIP đã giải nén/publish idempotent. Lượt kiểm 24/10: admin 47 test trên Laravel 12/13, core 152 trong release checkout (không gồm hai test routing ngoài phạm vi), compiler 282, client 560, builder 26; client typecheck đạt. UI editor/upload, CRUD User/role, settings audit/upload, AI, mobile/theme đầy đủ và tranh chấp DB nhiều connection vẫn chưa hoàn thiện. Không tự nâng dependency/lockfile starter hoặc ghi DB host. Cổng tổng thể ở trên tiếp tục áp dụng.

Mốc alpha: [RELEASE](../RELEASE.md) chốt core 2.4.0, compiler 3.0.0 và frontend GitHub tags. Các mô tả release còn thiếu phía trên là bối cảnh kế hoạch; chỉ phạm vi alpha trong release notes được công bố.

Đã kiểm consumer cài trực tiếp saola/admin 0.1.0-alpha.1 từ Packagist, không custom repository; xem [bằng chứng release](RELEASE_VERIFICATION.md).

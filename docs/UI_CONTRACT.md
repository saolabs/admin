# Hợp đồng shell và renderer admin v1

Frontend thuộc `resources/saola/admin`; lang backend thuộc `lang/{locale}`. `admin.manifest.json` khai package/version/contract, CSS, bootstrap và renderer suffix. Namespace view dùng context `admin.*`, không lặp tầng package; collision source được publisher chặn theo ownership/hash. Xem [quy ước tên và nâng cấp](NAMING.md). Không tự chọn theme hoặc quản lý theme trong package này.

## Page và dữ liệu

`PageRegistry` chỉ nhận class triển khai `Pages\Page` không giữ request/User trong registry. Definition có `key`, `titleKey`, `ability` và `document(Request): ResourceDocument`; class được resolve mỗi request. Host đăng ký trong `afterResolving(PageRegistry::class, ...)`. Không đăng ký cùng slug hai lần; slug `settings` được dành cho editor. Navigation và HTTP page đều kiểm Gate; giấu menu không thay kiểm backend.

`ResourceDocument` v1 là **read-only**: title/description là key, fields có key/labelKey/value text; columns có key/labelKey/renderer text hoặc badge; rows chỉ xuất scalar thuộc column allowlist. Giá trị được escape bởi renderer. Không serialize Eloquent model, secret hoặc HTML tùy ý. `listing` tùy chọn thêm search/sort/pagination từ ResourceListing/ListingQuery; page cũ nhận null và không hiện toolbar. Chưa có mutation/actions/options hoặc field authorization tự động; definition phải query và project theo Policy/scope trước khi tạo DTO. Category và User directory dùng cùng renderer, không copy HTML.

Page có thể triển khai `AuthorizesPage::allows(Request)` để bổ sung quyền theo request/model; PageRegistry áp cùng với Gate ability cho cả navigation và controller. [User directory](USERS.md) cần ability và model viewAny, query scope host bắt buộc; list/count/search phải cùng phạm vi.

Trang workspace đọc tên app/locale và revision các ngôn ngữ đã published từ DB, không tạo số liệu giả. Panel tắt mặc định, guard/Gate của host quyết định quyền. Middleware thành công trả private/no-store, Vary Cookie/Authorization/Accept-Language; khôi phục app locale sau response/exception để worker không giữ ngôn ngữ người quản trị trước. SSR và page-data cùng kiểm DictionaryAccess trước scoped snapshot; không lấy catalog đầy đủ để nhúng vào web.

## Client, locale và tài nguyên

`pages.resource` dùng `@await`; layout props runtime được giữ cả Blade/JS. Shell có sidebar, menu responsive, slot content, lựa chọn locale và thông báo. Nhãn `__()` trong `.sao` được compiler hạ sang `text()`, dynamic label gọi `text(key)`.

`AdminUI.registerLanguageGuard(() => !dirty)` trả disposer; form phải dispose khi pause/destroy và đăng ký lại khi resumed. Shell gọi guard trước/sau tải dictionary qua client `changeLanguage`. Settings form đăng ký thêm `Router.addNavigationGuard`, beforeunload và gỡ đúng lifecycle; guard không ghi đè beforeEach của host. Chưa có persistence preference. Không đăng ký model/actor/auth snapshot trong service singleton.

CSS scope `.saola-admin`, token `--sa-admin-*`, layout/resource key và slot content là điểm tương thích cho theme tương lai. CSS/SVG nguyên bản của package, font hệ thống; không chép Bootstrap/plugin/template assets. Xem [inventory](ASSET_INVENTORY.md). Theme quản lý cài/bật/tắt/publish/override lifecycle thuộc package riêng. Manifest/slot/token mới là nền tương thích, chưa là API theme management đầy đủ.

`BundleManifest::cssUrl('admin')` dùng `sao.assets.css.admin`, dev URL source hoặc manifest production (cả CSS import từ JS entry). Web mặc định vẫn `resources/css/app.css`. Build source cùng đợt compiler/client/core để marker SSR/hydration và props đồng bộ. Publisher không chép output generated hoặc runtime riêng.

## Settings renderer

`pages.settings`/`components.settings-form` dùng cùng layout slot và metadata từ SettingsRegistry. `SettingsForm` client chỉ giữ draft của view, patch field thay đổi, revision/CSRF và trạng thái request. Thiếu bản dịch nội dung hiện fallback nhưng không tự gửi; text/boolean/integer đều cùng renderer, extension không copy form. Locale nội dung chọn bằng URL và độc lập locale UI; xem [Settings contract](SETTINGS.md).

Server đăng ký route editor trước catch-all và refresh name/action lookups sau khi thêm route trong booted(); workbench mới khởi động kiểm được lỗi mà Testbench tự refresh trước đó từng che khuất. HTTP JSON settings vẫn riêng `/{prefix}/settings/{group}`, không được đăng ký như component route.

## Đã kiểm và còn thiếu

PHP tests compile source publish thật, request SSR/page-data, auth/Gate, private projection, routes và CSS. Client tests dùng HTML Laravel thật để hydrate, CSR, đổi locale, guard và strict TypeScript. Preview Vite build từ source publish đã chạy trong browser ở lượt trước; không dùng fixture server để chứng nhận production auth. Test form kiểm draft/422/409/network/revoked permission/response cũ, strict TS của cả compiled view và module state. Workbench Laravel sống với operator giả/SQLite tạm kiểm session+CSRF và save/revision. Composer archive đã giải nén và publish idempotent. Đã kiểm cài từ Composer ZIP với core/compiler của Packagist, Laravel 12/13 và build từ client/builder GitHub tags, không cần sibling. CI bổ sung PHP 8.3/8.5. Consumer mới cài trực tiếp saola/admin alpha từ Packagist đã kiểm provider/install/migrate/sync/text. Mobile/browser trên host thật, route/theme overrides và DB MySQL/Postgres vẫn cần nghiệm thu.

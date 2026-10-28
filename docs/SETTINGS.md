# Settings schema và DB store

Trạng thái source 25/10/2026 (chưa có trong tag alpha.1): registry/schema, nhóm con Website/Hệ thống, DB store, HTTP JSON và form từ schema có quyền/revision. Logo/favicon/ảnh SEO dùng [thư viện media và picker dùng chung](MEDIA_LIBRARY.md), giữ URL string tương thích dữ liệu cũ; chưa có audit/lịch sử hoặc tự áp dụng settings lên website.

Settings UI locale và content locale là hai dữ liệu độc lập. `app()->getLocale()` chỉ chọn nhãn UI; nội dung lấy theo locale truyền tường minh. Source mới lấy danh sách/default nội dung từ `LocaleManager::contentPolicy()` trong DB; `saola-admin.settings.content_locales/default_locale` chỉ là bootstrap trước lần lưu đầu tiên. Xem [quản lý ngôn ngữ](LANGUAGE_MANAGEMENT.md) (chưa nằm trong tag alpha.1). Không tự đổi theo locale giao diện/DB dictionary. Tạm tắt locale không xóa bản dịch; bật lại vẫn lấy được giá trị cũ.

## Đăng ký nhóm

Các nhóm mặc định được khai báo tại `DefaultSettings`, dùng cùng một renderer:

| Cụm / group | Trường chính | Phạm vi dữ liệu |
| --- | --- | --- |
| Website / `general` | Tên website, mô tả, khẩu hiệu, địa chỉ website, bản quyền | Văn bản theo locale; URL dùng chung |
| Website / `branding` | Logo, logo nền tối, favicon | Dùng chung |
| Website / `seo` | Meta title/description/keywords, tiêu đề/mô tả/ảnh chia sẻ, cho phép lập chỉ mục | Văn bản theo locale; ảnh và cờ lập chỉ mục dùng chung |
| Hệ thống / `system` | Chế độ và thông báo bảo trì | Duy nhất, không theo locale |
| Hệ thống / `system-display` | Múi giờ, định dạng ngày/giờ, số mục mỗi trang | Duy nhất, không theo locale |

Không thêm nhóm liên hệ, mạng xã hội hoặc pháp lý. Key `general.contact` từ alpha.1 vẫn được giữ trong storage/API để không mất dữ liệu cũ, nhưng không nằm trong UI mặc định và không thuộc public projection. Các trường mới thêm cùng schema version 1 vì giữ nguyên kiểu dữ liệu đã lưu; không tự di chuyển/xóa document cũ.

Website dùng Gate `view-site-settings`/`update-site-settings`; System dùng `view-system-settings`/`update-system-settings`, cấu hình tại `settings.system_read_ability/system_write_ability`. Quyền vào panel và quyền từng nhóm được kiểm riêng. Có thể tắt `settings.register_defaults` rồi đăng ký schema của host. Không tự cấp grant khi cài/nâng cấp.

Mỗi group có `category`/`categoryTitleKey` tùy chọn, mặc định Website. Cụm xuất hiện thành submenu trong sidebar: Thiết lập → Website/Hệ thống. Trong nội dung chỉ hiện các tab ngang thuộc cụm đang chọn; không có cột tab dọc bên cạnh menu. Tab dài cuộn ngang trên màn nhỏ; một nhóm duy nhất không cần thanh tab. Menu/tab chỉ project nhóm có quyền đọc; cụm không có nhóm được phép sẽ ẩn. Group mới vẫn chỉ cần đăng ký schema, không thêm `.sao`.


Host provider đăng ký thêm nhóm, không cần khai báo route mới:

```php
use Saola\Admin\Settings\{SettingsGroup, SettingsRegistry};

$this->app->afterResolving(SettingsRegistry::class, function (SettingsRegistry $registry) {
    $registry->register(new SettingsGroup(
        key: 'catalog', titleKey: 'catalog.settings.title',
        readAbility: 'view-catalog-settings', writeAbility: 'update-catalog-settings',
        fields: [
            'heading' => ['labelKey' => 'catalog.settings.heading', 'type' => 'text', 'default' => '', 'localized' => true, 'public' => true, 'maxLength' => 200],
            'page_size' => ['labelKey' => 'catalog.settings.page_size', 'type' => 'integer', 'default' => 20, 'min' => 1, 'max' => 100],
            'enabled' => ['labelKey' => 'catalog.settings.enabled', 'type' => 'boolean', 'default' => true],
        ], version: 1,
    ));
});
```

Tên nhóm là slug; key field là tên ổn định không dấu chấm, không nhận code/HTML/model từ người quản trị. Schema tin cậy do developer đăng ký, duplicate group bị chặn. `text/textarea/url/select/boolean/integer` có kiểu nghiêm ngặt; không nhận `"false"` thay boolean hoặc null thay bản dịch rỗng. Text tối đa 2048 ký tự mặc định; `maxLength` 1–16384, integer có min/max. Default được kiểm theo constraint. Secret/token/password/file không thuộc store bản đầu; text private chỉ hạn chế projection, **không mã hóa at rest**.

Đăng ký label key trong `lang/{locale}/*.php` qua LanguageSources và mở rộng dictionary scope allowlist của panel phù hợp khi thêm nhãn extension. Field public không đồng nghĩa label key được công khai qua dictionary, hai cơ chế có mục đích riêng.

## Giao diện từ schema

Mở `/{prefix}/settings?group=general&content_locale=vi`, sau khi có quyền đọc group. Menu chỉ hiện Settings khi có ít nhất một group được đọc; tab nhóm cũng lọc Gate. Route editor là `saola.admin.settings.editor`, có SSR/SPA và dùng cùng shell/dictionary scoped. Key `settings` được dành riêng cho editor. Nhóm mới đăng ký như ví dụ trên tự có form, không cần `.sao` mới.

Renderer có text, textarea, URL, select, checkbox boolean và number integer; nhãn/mô tả/thông báo lấy key DB. Group có bản dịch hiển thị selector nội dung; field dùng chung trong group đó có nhãn riêng. Group chỉ có field dùng chung không có selector, `contentLocale` rỗng, `contentLocales=[]`, không gọi content locale policy. Mặc định kế thừa không rỗng có gợi ý dưới field. Dữ liệu fallback chưa chạm không tự được ghi; tương tác với field thiếu là tạo giá trị riêng, kể cả rỗng hoặc bằng fallback. Nút **Bỏ thay đổi** chỉ phục hồi draft về snapshot đã đọc, không xóa giá trị DB hoặc reset fallback.

Selector ngôn ngữ nội dung lưu trong query `content_locale`; chỉ chuyển khi đã lưu/bỏ draft. Đổi ngôn ngữ UI giữ selector nội dung và không ghi sang locale khác. Khi còn draft hoặc request đang chạy, chặn đổi UI locale, route và selector nội dung; beforeunload yêu cầu browser bảo vệ khi reload/đóng tab. Client dùng `Router.addNavigationGuard()` trả disposer, không thay `beforeEach` của host. Guard/listener được gỡ khi pause/stop/destroy và gắn lại khi resume; Back/Forward bị chặn khôi phục URL trong cả history/hash mode.

Lưu chỉ gửi field đã sửa và revision đã đọc; disable submit/fieldset khi pending. Lỗi 422/409/network/401/403/419 giữ input. Sau 409, bỏ draft rồi **Tải lại từ máy chủ** trước khi sửa tiếp, không tự retry bằng revision mới. Response cũ sau khi view bị suspend bị bỏ qua; write chưa xác nhận bắt buộc reload. Response thành công sai shape/revision không xóa draft. Instance form nằm trong view, service AdminUI không giữ tài liệu/actor toàn cục. Read-only actor thấy form và giá trị nhưng không sửa được; server vẫn kiểm quyền khi ghi.

## Đọc và ghi

Khi panel bật, `GET|PUT /{prefix}/settings/{group}` dùng web session/CSRF, AdminAccess và Gate của group. GET trả raw document + metadata/schema + `canUpdate`; 401/403/404/422/409 là status HTTP thật. Response thành công private/no-store. API không tự mở grant. Không có route SPA cho JSON settings này.

PUT chỉ nhận JSON, tối đa 64 KiB, dạng:

```json
{
  "revision": 0,
  "patch": {
    "values": {"website_url": "https://example.test"},
    "localized": {"en": {"website_name": "Website"}, "vi": {"website_name": ""}}
  }
}
```

`values` chứa field dùng chung, `localized.{locale}` chứa field theo locale; key lạ/sai section/locale chưa hỗ trợ/sai kiểu bị chặn. Chỉ gửi field thay đổi, không gửi revision/schema trong values. Không có thao tác xóa/reset về fallback trong API hiện tại. Lưu rỗng là có chủ đích, không phải thiếu. Controller đọc JSON body gốc để global TrimStrings/ConvertEmptyStringsToNull của host không làm mất giá trị đó.

Document chưa lưu có revision 0, đọc không tạo hàng DB. Mỗi lượt ghi kiểm revision bằng conditional update trong transaction và tăng một; stale revision trả 409, không đè dữ liệu. Schema version khác bản lưu trả 409 và yêu cầu migration có chủ đích. Đây là kiểm CAS trên SQLite workbench; tranh chấp trên nhiều connection MySQL/PostgreSQL còn phải nghiệm thu.

Đọc phía website qua service, không share raw document vào public HTML:

```php
use Saola\Admin\Settings\{SettingsRegistry, SettingsStore};

$group = app(SettingsRegistry::class)->get('general');
$website = app(SettingsStore::class)->values($group, $contentLocale, publicOnly: true);
```

Reader dùng giá trị locale đã lưu → locale nội dung mặc định → default schema. Không ghi fallback vào DB, không xóa giá trị của locale khác. Field extension tạm bỏ khỏi schema vẫn giữ trong DB khi lưu field khác; read/API chỉ project field đang đăng ký, không lộ giá trị dormant. Raw `read()` phân biệt key thiếu và chuỗi rỗng; `values()` là projection đã resolve. `publicOnly:true` chỉ trả field có `public=true`, không bao gồm revision/schema/private. Group có field localized chỉ nhận locale nội dung đang bật; bỏ tham số dùng default nội dung. Group shared không cần và không phụ thuộc locale.

Tên bảng không lặp admin; xem [quy ước tên/prefix](NAMING.md). Bảng `saola_settings` có `group_key`, `schema_version`, `revision`, JSON text `document`; tên bảng/connection tại `saola-admin.settings`. Host có thể bind lại `SettingsStore` để dùng store hiện hữu. Không sửa `.env`, `config/app.php` hay các bảng của hệ thống cũ. Website tự quyết định nơi dùng projection và policy nội dung. Các giá trị vận hành ở đây là cấu hình được lưu để host đọc: package chưa tự bật Laravel maintenance, đổi timezone của worker, xuất thẻ SEO hoặc tạo robots.txt. Host nối vào middleware/renderer của mình; không suy rằng lưu một flag đã kích hoạt hành vi toàn website.

Workbench đã kiểm SSR thật → hydrate/CSR/form, dirty guard, draft/error/revision, locale nội dung vi với UI en, và fresh Laravel boot. HTTP sống với session/SQLite tạm kiểm CSRF 419, save 200, stale 409, validation 422 và nhóm thiếu 404. Bước tiếp theo là audit, User adapter/CRUD và kiểm DB nhiều connection/cache theo cổng kế hoạch.

## Giá trị dùng chung và kiểu field mở rộng

```php
$system = app(SettingsRegistry::class)->get('system-display');
$runtime = app(SettingsStore::class)->values($system); // không có tham số locale
```

Mỗi group vẫn có một row/document và revision duy nhất. Với group shared, backend từ chối `localized` ngay cả khi UI bị bỏ qua; đổi UI locale hay tham số content_locale không tạo document khác. System mặc định không có field public, `publicOnly:true` trả projection rỗng.

`SettingsStore::values` hiện nhận `?string $locale = null`; adapter override cần cập nhật chữ ký và semantics nhóm shared. Trường URL chỉ nhận http/https hoặc rỗng; `relative:true` cho phép đường dẫn bắt đầu bằng một `/` cho asset, không nhận `//`, javascript/data URL. Select khai báo `options` là map string value → string label, default phải nằm trong map, dữ liệu gửi lên được kiểm theo options; textarea vẫn lưu string và bảo toàn xuống dòng/rỗng. Constraint min/max/maxLength được kiểm ở server.

Đã đối chiếu các schema `siteinfo/system` của ai-services và m-ai, chỉ giữ phần thông tin website/SEO/nhận diện cần chung. Bộ test có phân quyền từng cụm, một giá trị hệ thống qua en/vi, chặn ghi theo locale, URL/option/range sai, giữ dữ liệu alpha.1, SSR và hydrate/form/guard.

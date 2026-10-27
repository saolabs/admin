# Thiết kế lang động — catalog key, database và client

Ngày: **24/10/2026**. Trạng thái: đã triển khai nguồn locale-first, sync DB, resolver/compiler, JSON preview/draft/publish, dictionary có quyền và demo starter. Source sau alpha.1 đã có UI thêm locale, mặc định DB và upload JSON; AI/editor từng key còn là kế hoạch. Xem [hợp đồng triển khai](LANGUAGE_MANAGEMENT.md), chưa có trong tag Packagist alpha.1. Hợp đồng và cách dùng API/CLI hiện có: [cách cài và CLI](../README.md). Liên kết với [kế hoạch saola/admin](ADMIN_PACKAGE_PLAN.md).

**Hợp đồng chính:** file PHP trong `lang/{locale}/` khai báo cây key–value và seed ban đầu, registry/config giữ metadata riêng → đồng bộ vào DB → PHP/client đọc bản dịch đã công bố từ DB hoặc cache của DB. Thêm ngôn ngữ, nhập JSON hoặc AI dịch không yêu cầu sửa `.sao`, viết thêm PHP lang hay build lại JS.

## 1. Key ổn định, không lấy văn bản làm key

Trong biểu thức của file `.sao`:

```text
__('admin.identity.auth.login.keep_login')
```

Compiler chuyển lời gọi này sang `text()` ở đầu ra PHP và helper `text()` tương ứng ở client. Ví dụ lời gọi PHP sau biên dịch:

```php
text('admin.identity.auth.login.keep_login');
```

`__()` trong `.sao` là cú pháp do compiler xử lý. **Hàm `__()` của Laravel giữ nguyên**, không override/redefine và không đổi binding translator/loader của Laravel để phục vụ catalog DB. PHP viết tay dùng `text()` cho catalog này.

Dạng tổng quát là **object key–value có thể lồng nhau**: mỗi value là chuỗi dịch hoặc object con. Có thể có key ngay ở root, các nhánh sâu khác nhau và nhiều cấp tùy nhu cầu; không bắt buộc context, module, submodule, group hay một số cấp cố định. Những tên này chỉ là quy ước tổ chức tùy chọn của bên khai báo. Helper nhận đường dẫn `key` hoặc `key1.key2...`; dấu chấm phân cấp, không nằm trong tên thuộc tính JSON. Key là định danh ổn định, hỗ trợ dấu gạch dưới/gạch nối như `keep_login`, `module-name`; đổi câu gốc không đổi key. Owner, phạm vi truy cập và loại thông điệp là metadata riêng, không suy từ tên hoặc vị trí các cấp.

Ví dụ `keep_login` hợp lệ ngay ở root hoặc nằm trong object bất kỳ, được gọi bằng đường dẫn đầy đủ tương ứng. `keep login` và câu `Keep me signed in` không được dùng làm key của catalog mới. Catalog kiểm trùng key, giới hạn độ dài và cấm path nguy hiểm như `__proto__`, `constructor`, `prototype` khi dựng object client. Không cho một key vừa là leaf vừa là parent của key khác.

Mọi label/hint/option/confirmation/error do module mới khai báo dùng full key. `.sao` gọi `__()` được compiler hạ xuống `text()`; PHP/client `text()` dùng cùng contract catalog, params/locale/fallback và snapshot DB tương ứng. Laravel/third-party tiếp tục dùng hệ thống dịch sẵn có độc lập; không chuyển lời gọi PHP `__()` của chúng sang catalog DB.

## 2. Phân tầng và vòng đời dữ liệu

| Phần | Trách nhiệm |
| --- | --- |
| File trong `lang/{locale}/*.php` | Mảng key–value lồng tùy ý để seed; project/package cùng quy ước, không thuộc cây frontend hoặc Vite |
| Registry/sync | Đăng ký catalog từ host/admin/extension/theme tin cậy, phát hiện trùng key, sync idempotent có dry-run |
| DB translation store | Locale, key, source revision, giá trị dịch/draft, revision đã công bố; nguồn chuẩn lúc chạy |
| PHP text resolver | Nối store/cache vào helper `text()` của Saola; request scope, không query từng key; giữ nguyên Laravel translator |
| Client TranslationService | Tra key đồng bộ từ snapshot đã nạp; nạp snapshot động qua bootstrap/API trước khi render/chuyển locale |
| Admin UI | Danh sách locale/độ phủ, duyệt/sửa theo cây key, xuất/nhập JSON tổng, công bố và chạy job dịch AI |

Core giữ contract/helper tích hợp trung lập, client giữ lookup và nạp dictionary. `saola/admin` cung cấp UI quản trị và DB store/migrations/binding cho toàn bộ catalog đã đăng ký, không chỉ nhãn của /admin. Host có store khác thì dùng adapter. Không cần mở thêm package lang riêng lúc này; core/client không phụ thuộc ngược vào class nghiệp vụ của admin.

Cấu trúc nguồn đã chốt và triển khai:

```text
project/lang/en/saola.php
project/lang/vi/saola.php
package/lang/en/admin.php
package/lang/en/common.php
package/lang/vi/admin.php
package/lang/vi/common.php
```

File trả về mảng PHP có leaf là chuỗi, cùng cây ngữ nghĩa với JSON xuất/nhập ở §4; không còn `resources/saola/admin/lang`, `lang/seeds` hay catalog JSON bắt buộc trong cây frontend. Tên file không quyết định context hoặc prefix: `lang/vi/admin.php` trả `['save' => 'Lưu']` thì key Saola là `save`. Đây là semantics của bộ sync Saola, không phải cách Laravel `__()` tự resolve file.

Project chọn file qua `sao.i18n.source_files` (mặc định `['saola']`); package đăng ký `LanguageSources::register(owner, root, files, overrideRoot?)`. Không quét nhầm mọi file `auth.php`/`validation.php` của Laravel. `saola/admin` đăng ký `admin`/`common`; nội dung mảng của package hiện chủ động có nhánh `admin`, không được prefix thêm lần nữa.

Không cần publish file lang để package sync được. Nếu muốn tùy chỉnh seed bằng source, tag `saola-admin-lang` publish vào `lang/vendor/admin/{locale}/*.php`; registry ghép override theo leaf trong cùng owner/file trước khi sync. Đường dẫn vendor không tham gia key. Bản dịch vận hành ưu tiên sửa DB; không tự ghi DB ngược ra file. Metadata owner/file/path/source hash được registry/store giữ riêng; context chỉ là nhánh tùy chọn trong dữ liệu.

File seed không là nơi người vận hành ghi bản dịch runtime; sửa DB không ghi ngược vào vendor/lang/resources. Cài lần đầu sync cấu trúc và insert giá trị còn thiếu. Khi nâng package: thêm key mới, cập nhật source/config revision, đánh dấu bản dịch cần rà nếu source đổi; không ghi đè bản dịch người dùng/AI đã lưu. Nguồn seed đổi chỉ tự cập nhật giá trị còn nguyên trạng seed cũ theo hash; giá trị đã sửa giữ nguyên. Retire key bỏ khỏi source và rename/mapping là bước tương lai; nền hiện tại chưa tự retire/xóa key.

Sync chỉ chạy qua lệnh/cài đặt/upgrade có chủ đích; không quét source và ghi DB trên mọi request. DB/cache không khả dụng phải báo tình trạng vận hành; không âm thầm coi file seed cũ là bản dịch hiện hành. Trước khi cài xong DB, màn installer có thể dùng thông báo bootstrap tối thiểu tách rõ khỏi runtime đã kích hoạt.

## 3. Schema nền đã triển khai và phần mở rộng dự kiến

Migrations dùng prefix `saola-admin.language.table_prefix` (mặc định `saola_`) và connection riêng nếu cần. Hiện có `locales` (code/revision), `translation_keys` (path/owner/source_file/source_locale/source_text/source_hash), `translation_values` (key_id/locale/published_value/origin/seed_hash/needs_review) và `language_batches` (UUID/kind/actor/locale/status/base/result revision/payload hash/payload/expiry/timestamps). Export baseline và import draft lưu ở batch, tách khỏi published values; chưa có version lịch sử cho từng leaf. Các cột/trạng thái đầy đủ dưới đây là đích phát triển tiếp:

| Bảng | Nội dung và ràng buộc |
| --- | --- |
| `saola_locales` | code unique, tên bản địa/hiển thị, dir, status draft/enabled/disabled, published_revision; code chuẩn hóa thống nhất, không tạo hai locale tương đương do case/hyphen |
| `saola_translation_keys` | full key/path unique, owner, metadata tổ chức tùy chọn, source_locale/source_text/source_revision, params, format plain/plural, description, active/retired; payload cấu trúc chỉ developer/catalog sync được đổi |
| `saola_translation_values` | key_id + locale unique, value nullable, status draft/published/needs_review, origin seed/human/import/ai, source_revision_at_translation, revision, updated_by |
| `saola_language_batches` | batch UUID, kind import/ai/publish, target locale, catalog/base revision, payload hash/idempotency key, status, tiến độ/thống kê/lỗi; audit nguồn thay đổi |

Bản dịch draft và published phải tách được: lưu draft không thay giá trị published đang phục vụ. Có thể dùng cột draft/published riêng hoặc bảng version; chốt schema cụ thể trước migration. Công bố một batch tạo snapshot bất biến theo locale revision rồi đổi con trỏ active trong transaction; không đọc một nửa bộ dịch mới một nửa bộ cũ. Snapshot/cache là projection có thể tái tạo từ DB/version, không phải source thứ hai nằm trong assets.

Phân biệt **chưa dịch (`null`/không có)** với **chuỗi rỗng có chủ đích**. Runtime fallback: giá trị published locale yêu cầu → giá trị published fallback locale của catalog → full key. Source/default cũng được seed vào DB; không đọc file source trên đường runtime để lấp lỗi. Fallback không tự ghi thêm bản dịch. Bản đầu dùng một fallback locale xác định, không tạo chuỗi fallback có chu trình.

Tên site, mô tả, thông tin liên hệ theo locale vẫn là **Settings values**; bản dịch của label “Tên website” là **Translation value**. Hai loại có store/validation/lifecycle riêng; không tự đưa dữ liệu hoặc secrets của Settings vào file dịch tổng.

## 4. Xuất một JSON tổng và nhập lại

Màn ngôn ngữ cho chọn locale đích rồi **Tải file dịch đầy đủ**. Một file chứa toàn bộ cây key–value đã đăng ký trong phạm vi người quản trị có quyền xuất, kể cả key chưa dịch. Có thể lọc nhánh/thiếu dịch khi cần; không bắt người dùng sửa nhiều file lang nhỏ.

**Cấu trúc file là cây lồng trực tiếp**, đúng dạng người dùng yêu cầu:

```json
{
  "save": "Lưu",
  "auth": {
    "keep_login": "Duy trì đăng nhập"
  },
  "admin": {
    "module-name": {
      "group": {
        "iten": "đối tượng"
      }
    }
  }
}
```

`save` là leaf ngay ở root; `auth.keep_login` và `admin.module-name.group.iten` là các leaf ở độ sâu khác nhau, đều hợp lệ. Các tên `admin`, `module-name`, `group` không có ý nghĩa bắt buộc đối với resolver. Mọi leaf là chuỗi; các object chỉ gom nhánh tùy ý. Không thêm lớp bọc hay metadata vào cây bản dịch; `groups`, `items`, `source`, `value`, `schema` nếu được bên khai báo dùng làm key thì cũng chỉ là key thông thường, không phải tên dành riêng của định dạng. Tên thuộc tính giữ nguyên, bao gồm dấu gạch nối.

Truy cập các leaf trên trong biểu thức `.sao`:

```text
__('save')
__('auth.keep_login')
__('admin.module-name.group.iten')
```

Các lời gọi PHP sau biên dịch tương ứng:

```php
text('save');
text('auth.keep_login');
text('admin.module-name.group.iten');
```

Mảng PHP nguồn seed và JSON xuất/nhập biểu diễn cùng cây này; khác cú pháp PHP array/JSON object, không thêm prefix từ tên file. Dấu chấm chỉ là cách gọi đường dẫn; DB có thể lưu full key nội bộ rồi dựng lại cây khi xuất. Người dịch thay chuỗi ở leaf, giữ nguyên toàn bộ tên thuộc tính và cấu trúc. Placeholder như `:name` nằm trong chuỗi và phải được giữ khi dịch.

Locale đích, tên ngôn ngữ, hướng chữ, catalog/source revision, placeholder/format và base translation revision được quản lý trong DB và màn xuất/nhập, **không thêm vào file bản dịch**. Người dùng chọn locale đích trên màn nhập; không lấy tên file làm căn cứ duy nhất. Locale mới được tạo dạng draft, chưa cần thêm file ngôn ngữ trong package hoặc bundle JS.

Để file đầy đủ chỉ có leaf dạng chuỗi, key đã dịch xuất giá trị thực của locale đó; key chưa dịch xuất source text để người dùng biên dịch. Server lưu baseline của lần xuất, gồm key thiếu và revision, gắn với phiên/batch xuất được chọn ở màn nhập. Khi nhập lại, leaf chưa dịch vẫn nguyên source được giữ trạng thái chưa dịch mặc định; preview cho xác nhận trường hợp bản dịch cố ý giống source. Không tự lưu source/fallback thành bản dịch mới chỉ vì có mặt trong file. Chuỗi rỗng vẫn là giá trị có chủ đích, khác trạng thái thiếu trong DB.

Luồng import: chọn locale và batch xuất nếu có → upload → parse/validate → xem tổng hợp mới/đổi/thiếu/xung đột → chọn áp dụng → ghi draft hoặc công bố batch. Những gì phải kiểm:

- UTF-8/JSON đúng cây object với leaf là string, giới hạn byte/số key/độ sâu, không duplicate object key. JSON có key lặp bị từ chối trước khi parser làm mất một giá trị; không chấp nhận array, leaf object metadata, số, boolean, null hoặc payload thực thi thay text.
- Full key dựng từ cây phải đã đăng ký, đúng scope/canonical format; file dịch không tự đăng ký key hoặc đổi source/params/cấu hình. Không kiểm điều kiện bắt buộc context/module/group hoặc độ sâu cố định; metadata tổ chức nếu có được quản lý riêng. Chặn path nguy hiểm và xung đột leaf/parent.
- Placeholder hợp lệ và không mất/thêm tên tham số; kiểm format plural theo catalog trong DB. Nhãn là text, escape khi render, không biến bản dịch thành template/HTML/JS thực thi.
- Khi có baseline xuất: so catalog/source revision và target translation revision với trạng thái đã xuất; người khác hoặc job đã sửa thì trả conflict để so lại, không đè bản mới. Khi không có baseline, không thể suy revision cũ từ file thuần text; bắt buộc preview theo trạng thái hiện tại, nhận diện source chưa dịch và xác nhận các thay đổi trước khi áp dụng. Commit kiểm lại revision đã dùng cho preview để chặn thay đổi đồng thời.
- Key không gửi tới không bị xóa; hỗ trợ nhập một phần theo cây cùng cấu trúc. Xóa/reset là thao tác tường minh riêng. Không overwrite các locale khác hoặc lưu fallback thành bản dịch thật.
- Request/batch có idempotency key và hash lưu phía server; gửi lại cùng payload không nhân lần ghi/công bố. Bản đầu import dưới trần cấu hình có transaction atomic; file quá lớn chia staging/job nhưng chỉ công bố qua một con trỏ snapshot đã hoàn tất.

File JSON chỉ chứa cây key → text, không chứa tài khoản, settings private hoặc secrets. Metadata phục vụ kiểm định và quản lý bản dịch ở DB/UI; không yêu cầu người dịch thêm lớp bọc hoặc tải kèm file metadata thứ hai.

## 5. AI dịch rồi lưu DB

Dùng cùng catalog, format và validator như import; không làm pipeline lưu riêng bỏ qua kiểm định. Người quản trị chọn source/target locale, toàn bộ hoặc nhánh/key thiếu dịch, provider được cho phép và ngân sách. Admin chỉ phụ thuộc `TranslationEngine` adapter; có thể nối AI service hiện có của host, không bắt cài Gehihi/Agent Store hoặc hardcode provider.

Job chia batch hữu hạn; gửi key/source/description/params/format, yêu cầu trả cây key → text cùng cấu trúc đã giao; adapter có thể dùng full key nội bộ để đối chiếu từng leaf. Không gửi Settings values hoặc dữ liệu người dùng để dịch nhãn UI. Validate tập key, placeholder/plural, kích thước và response schema trước ghi. AI không được sửa key, params hoặc gán giá trị null của locale này sang locale khác.

Mặc định chỉ dịch key thiếu; muốn thay bản dịch có sẵn phải chọn chế độ overwrite có preview. Mỗi key ghim source revision và target revision lúc giao việc; người dùng đã sửa hoặc source đổi trong lúc AI chạy thì đánh dấu conflict/needs_review, không đè kết quả mới. Retry/resume tái dùng kết quả batch đã lưu, có giới hạn attempts/token/thời gian và trạng thái lỗi rõ.

Kết quả AI **được lưu vào DB** với origin ai; mặc định draft để rà, có thể cung cấp tùy chọn tự công bố sau khi toàn batch qua validator theo chính sách cấu hình. Lưu draft và áp dụng giao diện là hai thao tác có trạng thái riêng. Người dùng thấy tiến độ/số key đã dịch/lỗi và chi phí biết được; dừng job giữ phần đã lưu, không báo đã dịch toàn bộ.

Không thực hiện gọi AI hoặc tiêu phí trong phiên thiết kế này. Đây là contract cho adapter/job tương lai; cài admin không tự gửi catalog tới provider.

## 6. Nối với PHP, client và cache

### PHP

Resolver `text()` của Saola tra toàn bộ đường dẫn trong cây DB, bao gồm key đơn ở root như `save` và key lồng ở mọi độ sâu. Không mặc định segment đầu là group Laravel, context hay module. `text()` đi qua contract store/cache riêng của Saola; không gọi vòng lại Laravel `__()` để resolve catalog và không thay translator/loader mặc định của host.

Default/params/locale của `text()` phải được định nghĩa rõ và tương thích chữ ký cũ khi migrate; khi không truyền locale dùng locale hiện tại, không giữ mặc định cứng vi như chữ ký cũ. Core `text()` hiện đã nối `TextResolver` scoped qua `LanguageStore`; mặc định core có empty store để host chưa cài DB adapter vẫn trả default/key. Cài admin chọn `DatabaseLanguageStore`; host có thể bind adapter khác. Chữ ký PHP/client là `text(key, replace = [], default = null, locale = null)`, hỗ trợ shortcut `text(key, defaultString)`.

Compiler nhận diện lời gọi helper `__()` trong biểu thức `.sao` và sinh lời gọi `text()` cho cả đầu ra SSR/PHP và CSR/client. Chuyển đổi theo cấu trúc biểu thức, giữ key động và ánh xạ params/default/locale đúng chữ ký `text()` đã chốt; không thay chuỗi hàng loạt trong nội dung hoặc sửa file PHP của host/vendor. Client đăng ký helper `text()` tương ứng qua `App.Helper`; tên/cách gọi cụ thể của code sinh tuân theo cơ chế compiler, không nhầm với method text của DOM.

Không query một lần cho mỗi `text()`: resolve snapshot theo locale/phạm vi tải/revision từ cache DB. Cache của resolver Saola phải theo request/revision và được invalidation sau publish, không để worker Octane giữ locale hoặc snapshot của request trước. Laravel `__()` và cache translator gốc không tham gia vòng đời catalog DB này.

### Client

Giữ lookup đồng bộ và params/pluralization của TranslationService hiện có; từ điển UI do DB cấp, không phụ thuộc JSON text-to-text của Laravel. Dictionary giữ nguyên cây key–value như file ở §4, bao gồm leaf ngay ở root. Client đã có `catalogs[locale] = { revision, messages: tree }` riêng cho `text()`, hỗ trợ key đơn/path lồng và giữ nguyên root leaf. Nhánh legacy `messages[locale].groups/json` vẫn dành cho `trans`/`choice`/helper `__` legacy khi gọi trực tiếp, không phải schema catalog Saola. Compiler `.sao` đã chuyển bare `__()` sang `App.Helper.text()`. Revision/ETag của dictionary đi qua metadata HTTP/bootstrap riêng. Nhánh `json` kiểu câu → câu không dùng cho catalog mới.

- SSR cấp `APP_CONFIGS.i18n` với locale hiện tại + fallback, scope và revision snapshot. HTML và dictionary initial phải khớp; không nhét mọi ngôn ngữ vào JS build.
- Đổi locale gọi LanguageClient ensure/load snapshot từ endpoint dictionary theo phạm vi tải → kiểm revision → cập nhật catalog atomically → gọi Helper.setLocale. Locale đã có trong DB nhưng chưa có ở browser được tải lúc chạy; không còn yêu cầu rebuild. Khi tải lỗi giữ locale/form cũ.
- `installLanguage/loadLanguage` validate/cài/thay snapshot theo scope/revision. `changeLanguage(locale, loader, guard)` kiểm guard trước/sau tải, bỏ thao tác cũ và chỉ chuyển khi tải thành công. Endpoint dictionary đã có: scope public tường minh hoặc user/Gate ability; kiểm quyền trước ETag/304. `initTranslations()` thay toàn config khi chuyển scope, không dùng để merge từng package. Cùng scope, `applyLanguageConfig()` nhận revision từ page-data trước render và giữ locale đã chọn. Xem cấu hình cụ thể ở hợp đồng chung.
- `Helper.setLocale()` hiện dựng lại route/view và phát locale-change; guard form chưa lưu trước khi gọi, lưu preference riêng. Request dictionary/page-data cũ không ghi đè locale mới; cache view/page-data cũng phân biệt locale/revision và auth scope.
- Sau khi bản dịch published đổi, request/page-data trả revision để client biết nạp lại snapshot; phiên đang mở có thể cập nhật ở lần điều hướng/refresh hoặc thao tác “Làm mới bản dịch”. Không buộc WebSocket/poll nền liên tục. Re-render có dirty guard, không tự xóa form chỉ vì có nhãn mới.

Cache key bao gồm phạm vi tải/locale + catalog revision + translation revision + fallback revision. Snapshot publication atomic, ETag/conditional request để tránh tải lại khi không đổi. Giá trị quản trị draft không trả ở dictionary runtime; public endpoint chỉ cấp catalog được khai báo public/client-visible, không lẫn quyền và dữ liệu private theo actor. Static/offline dist có thể xuất snapshot DB lúc deploy; ngôn ngữ mới trong chế độ offline cần đồng bộ snapshot, không hứa cập nhật khi không có kết nối.

## 7. Quyền, theme và nâng cấp

Màn Language/Translation là chức năng nền theo yêu cầu mới. Quyền theo enum và module path thực: locales READ/CREATE/UPDATE; translation editing UPDATE; import/export và AI job là submodule có quyền riêng, không gom mọi thao tác vào EXTRA chung. Catalog schema/key registration do developer/sync quản lý; người vận hành quản lý locale và giá trị qua UI/JSON/AI.

Theme/package sau này đăng ký catalog có owner rõ, nhưng dùng key canonical cùng contract; không dùng tên theme active để đổi identity mọi key chung của admin. Trùng key giữa hai owner bị chặn trừ override có chủ đích. Tắt/gỡ theme không xóa locale hoặc bản dịch host; revision catalog xác định key còn hoạt động. Theme management vẫn thuộc package riêng, không nằm trong hạng mục lang này.

Nguồn seed ở `package/lang/{locale}/*.php`; project dùng `lang/{locale}/*.php`, override package tùy chọn ở `lang/vendor/admin/{locale}`. Frontend admin chỉ có app/views/assets/manifest trong `resources/saola/admin`; lang là nguồn backend riêng vẫn thuộc cùng package. DB, batch/job, cache và snapshot runtime không copy ngược vào source hoặc xóa khi publish admin.

## 8. Thứ tự triển khai và nghiệm thu

1. Chốt canonical key/catalog JSON; schema locale/keys/values và sync seed không đè dữ liệu. Fixture có leaf ở root, nhánh một/nhiều cấp với độ sâu khác nhau, tên có gạch nối và params/plural.
2. DB store + PHP `text()` resolver + snapshot/revision; compiler `.sao` hạ `__()` xuống `text()` ở cả SSR/CSR. Chứng minh cùng key ở PHP/client trả cùng bản published, không query theo từng label, không rò cache Octane và Laravel `__()` giữ nguyên hành vi.
3. Editor locale/cây key + JSON tổng export/import preview/commit, xung đột/retry và thiếu/empty/fallback. Thêm locale mới bằng UI/import, không build lại.
4. Client load dictionary runtime, atomic replace/merge theo scope, locale switching/dirty guard và i18n compiler parity.
5. AI adapter/job, validation, progress/retry/cancel và bảo vệ chỉnh sửa tay. Nối host provider khi có cấu hình thực; không trì hoãn khả năng dịch thủ công/JSON vì chưa chọn AI.

Nghiệm thu bắt buộc: key không đổi khi text đổi; không nhận key là câu văn; mọi key xuất trong một file cây lồng trực tiếp, round-trip giữ nguyên root leaf/nhánh có độ sâu khác nhau/tên có gạch nối, không có lớp bọc metadata; không bắt buộc context/module/group; thêm ja qua import dùng ngay ở PHP/client không deploy; mất placeholder bị chặn; bản dịch nhập lặp không nhân dữ liệu; sửa tay trong lúc AI chạy không bị đè; sync package mới không mất bản dịch; draft không lộ runtime; publish làm mới snapshot/fallback/cache cả PHP và JS; lời gọi `__()` trong `.sao` biên dịch thành `text()` ở SSR/CSR với key literal/động và tham số được giữ đúng, không thay text thường hay Laravel `__()` trong PHP; form đang nhập không mất khi locale/revision đổi; theme mới bổ sung key không làm mất key host/admin.

Nền hiện tại đã có registry/tree, PHP resolver scoped, migrations/DB adapter, sync dry-run/idempotent/upgrade, publish primitive, compiler mapping, dictionary theo allowlist/Gate/ETag và client guards. `LanguageExchange` cung cấp export baseline → strict JSON preview → draft → publish tường minh; CLI `admin:lang:{export,import,apply}` dùng backend này. Metadata nằm ở DB, JSON tải ra vẫn là cây trực tiếp; source chưa dịch được giữ thiếu, trừ xác nhận tường minh. Retry batch đã published không nhân revision; catalog hoặc bản dịch đích thay đổi sau baseline/preview gây conflict. Giới hạn mặc định 2 MiB/10.000 thuộc tính/7 ngày; chưa có purge job.

Test SQLite/Testbench kiểm auth/cache, baseline/placeholder/duplicate key/conflict/actor/expiry và host controller fixture độc lập. Test client compile demo `.sao` thật rồi chạy CSR/locale/dirty guard và kiểm strict TypeScript. Chưa có UI editor/upload, AI, audit/version history hoàn chỉnh, full-browser hydration demo mới hoặc tranh chấp hai connection MySQL/PostgreSQL. UI tương lai kiểm quyền rồi dùng actor xác thực để gọi exchange; primitive `publish()` không phải upload controller. Theme management vẫn là package sau.

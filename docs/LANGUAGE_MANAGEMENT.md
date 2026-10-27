# Quản lý ngôn ngữ và mặc định trong DB

Trạng thái 24/10/2026: đã triển khai trong source đang phát triển, **chưa nằm trong tag Packagist `0.1.0-alpha.1`**. Màn hình `/{prefix}/languages` dùng chung shell, SSR/SPA và dictionary DB. AI, trình sửa từng key theo cây và preference theo tài khoản chưa có.

## Thêm ngôn ngữ và dịch

1. Mở **Ngôn ngữ**, nhập mã locale như `ja`, tên `日本語`, thêm rồi **Lưu thiết lập**. Có thể bật riêng cho giao diện hoặc nội dung. Locale mới chưa có dictionary nên chưa xuất hiện trong bộ đổi ngôn ngữ giao diện.
2. Chọn locale ở phần bản dịch, **Tải JSON tổng**. File chỉ chứa cây key → text của toàn bộ catalog đã sync, hỗ trợ leaf ở root và lồng tùy ý. Key chưa dịch lấy source text làm mẫu. Không thêm context/module/vendor cố định.
3. Dịch các leaf, giữ nguyên key và placeholder như `:name`. Giữ tên file `{locale}.{EXPORT_UUID}.json`; UUID là baseline lưu trong DB, không chèn metadata vào JSON. Nếu đổi tên file, chọn baseline trong danh sách gần đây hoặc nhập UUID thủ công.
4. Chọn file, **Xem trước**. JSON được gửi nguyên văn để máy chủ kiểm cả duplicate keys; không parse rồi stringify ở client. Preview chưa thay dictionary đang dùng. Màn hình hiển thị tối đa 100 thay đổi, tổng key chưa dịch; toàn bộ thay đổi hợp lệ vẫn nằm trong batch ở DB.
5. **Lưu bản nháp** để tiếp tục sau, hoặc **Xuất bản** với quyền riêng. Batch đã xuất bản được retry idempotent. Sau xuất bản, dictionary/revision phục vụ PHP/client ngay, không build lại assets. Chuyển trang/tải lại shell để nhận danh sách locale mới.

Key vắng mặt không xóa bản dịch cũ; chuỗi rỗng là bản dịch có chủ đích. Key mới vẫn bằng source được đánh dấu chưa dịch và không tự ghi thành bản dịch; CLI có `--confirm-source=path` cho trường hợp cần xác nhận giống source. JSON trùng key, path lạ, leaf không là chuỗi, placeholder sai bị từ chối. File mặc định tối đa 2 MiB/10.000 thuộc tính; batch mặc định sống 7 ngày, cấu hình tại `language.exchange`.

Baseline/export/import gắn với actor được máy chủ xác định từ guard, class và auth identifier. UUID không cấp quyền truy cập; actor khác không đọc/áp dụng batch. Web và CLI có identity riêng; không dùng UUID do CLI xuất làm baseline web. Khi revision đích hoặc catalog đổi, xuất lại file và xem lại thay đổi; không tự ghép ghi đè revision mới. Batch hết hạn phải tạo lại. Danh sách gần đây trả tối đa 20 batch của chính actor.

## Ba lựa chọn mặc định

| Thiết lập | Hiệu lực |
| --- | --- |
| `uiDefault` | Nhãn UI cho người chưa chọn ngôn ngữ; phải bật UI và có dictionary đã xuất bản |
| `uiFallback` | Bản dịch dự phòng khi UI thiếu key, cùng một giá trị cho PHP và client; phải có dictionary đã xuất bản |
| `contentDefault` | Fallback nội dung Settings; chỉ cần bật nội dung, không buộc có dictionary UI |

Các thiết lập và cờ enable lưu một document có revision tại `saola_language_settings`. Tên hiển thị lưu ở `saola_locales.name`. Mặc định bootstrap lấy từ `app.locale`, `app.fallback_locale`, `saola-admin.settings.content_locales/default_locale` cho tới lần lưu đầu tiên. Sau đó DB là nguồn cấu hình vận hành; không sửa `.env`/`config/app.php`. Prefix/connection dùng chung `language.table_prefix/connection`; có thể bỏ `saola_` như các bảng lang khác.

Đổi mặc định không đổi lựa chọn đang dùng của người quản trị, không đổi locale nội dung đang biên tập. Thứ tự chọn UI: locale do SPA gửi qua `X-Sao-Response: json` + `Accept-Language`, hoặc query `locale` cho lượt mở trang → lựa chọn trong web session → ngôn ngữ browser nếu bật `language.detect_browser` → `uiDefault`. Browser detection mặc định tắt. Session dùng `saola.ui_locale`, chưa lưu vào User hoặc bảng preference. Middleware khôi phục app locale sau response/exception.

Không thể tắt ngôn ngữ đang là default/fallback nếu chưa chọn ngôn ngữ hợp lệ khác trong cùng lượt lưu. Tắt không xóa locale, dictionary hoặc nội dung; bật lại giữ dữ liệu cũ. Locale tắt UI hoặc chưa xuất bản không được dictionary endpoint phục vụ, kể cả có ETag cũ. UI chỉ cần ít nhất một bản dịch xuất bản; có thể còn thiếu nhiều key và dùng fallback.

Website ngoài panel tự chọn thời điểm áp dụng UI policy:

```php
Route::middleware(['web', 'saola.locale'])->group(function () {
    // Các route website cần dùng cùng lựa chọn UI từ DB/session.
});
```

Middleware này chọn UI locale; website vẫn truyền content locale tường minh vào `SettingsStore::values()`. Đọc danh sách/default nội dung động bằng `app(Saola\Admin\Language\LocaleManager::class)->contentPolicy()`. Host có thể giữ cơ chế chọn locale riêng; admin không tự gắn middleware lên toàn website.

## Quyền và API

Ngoài Gate vào panel, host phải nối các ability sau về hệ thống quyền của mình. Provider không tự cấp quyền:

| Ability mặc định | Thao tác |
| --- | --- |
| `view-admin-languages` | Menu/trang, đọc metadata và batch của mình |
| `manage-admin-languages` | Thêm/đổi tên/cờ enable và ba mặc định |
| `export-admin-languages` | Xuất toàn bộ catalog đã đăng ký, gồm key ngoài scope dictionary admin |
| `import-admin-languages` | Preview JSON, mở và lưu draft của mình |
| `publish-admin-languages` | Xuất bản; đồng thời cần quyền import |

Có thể đổi tên tại `saola-admin.language.abilities`. Quyền export/import/publish áp dụng toàn catalog, không chỉ nhãn admin hoặc một nhánh; cấp cho người quản lý bản dịch của toàn website. Dictionary public/private và allowlist của client tiếp tục kiểm riêng, không mở rộng theo quyền export.

Khi panel bật, các route dùng session/CSRF, AdminAccess và Gate từng thao tác, private/no-store:

| Route dưới `/{prefix}` | Hợp đồng |
| --- | --- |
| `GET /languages` | Trang SSR/page-data `admin.pages.languages` |
| `GET /_api/languages` | Metadata, capabilities, CSRF, batch của actor |
| `PUT /_api/languages` | `revision`, ba mặc định, `locales: [{code,name,uiEnabled,contentEnabled}]`; không gửi dictionary revision |
| `POST /_api/languages/{locale}/export` | Body `{}`, trả `export: {batch,revision,tree}` và editor mới |
| `POST /_api/languages/{locale}/preview` | `json` nguyên văn, `exportBatch`, tùy chọn `confirmSource` là path array |
| `GET /_api/languages/batches/{uuid}` | Preview/draft/published của chính actor |
| `POST /_api/languages/batches/{uuid}` | `{publish:false}` lưu draft; `{publish:true}` xuất bản |

Metadata dùng CAS revision trong transaction. 422 giữ draft và hiện lỗi; 409/410 yêu cầu tải lại/baseline mới; 401/403/419 giữ draft và dừng ghi tới khi tải lại. Client chặn route/UI locale/đóng tab trong khi còn draft hoặc đang đọc file/gửi request. Phản hồi về sau khi view suspend không được ghi ngược vào view. Bỏ thay đổi chỉ bỏ draft cục bộ. Đọc trang/API không seed DB.

## Nâng cấp và kiểm chứng

Với bản package có tính năng này: migrate để thêm schema mới, `admin:publish --dry-run`/publish và build lại **một lần cho giao diện quản lý mới**, `admin:lang:sync --dry-run`/sync để nạp nhãn mới. Sau đó thêm/sửa bản dịch qua DB không cần rebuild. Config đã publish của host cần merge nhóm `language.abilities`/`detect_browser` nếu muốn tùy chỉnh; provider vẫn có defaults. `admin:doctor` kiểm renderer mới và `language_settings`. Migration thêm không xóa bản dịch cũ; không chạy migrate:fresh trên DB hiện có.

Đã kiểm 54 test/505 assertions trên Laravel 12 và 13 (PHP 8.5), client 568 test và typecheck, SQLite/Testbench, Laravel SSR thật → hydrate/SPA, strict TypeScript, session/CSRF qua HTTP sống, export → preview → draft → publish, actor isolation, lỗi JSON/expiry/revision, fallback và rollback migration. MySQL/Postgres tranh chấp nhiều connection và browser/mobile của host vẫn cần nghiệm thu. Theme management là package riêng; nguồn UI/asset mới tiếp tục nằm trong root admin, lang seed ở `lang/{locale}`.

Kiểm lại 25/10: sửa HTTP 500 khi SSR lịch sử xuất/nhập đã có dữ liệu. Key trạng thái dùng `sprintf` tương thích PHP/client thay cho phép `+` còn sót trong PHP sau biên dịch. Regression tạo export/preview/draft trước khi render SSR; HTTP còn kiểm tải lại trang sau publish. Admin đạt 54 test/515 assertions và kiểm hydrate/strict TS liên quan. Client `main` đã đồng bộ v1.3.0 cùng dist, commit `7a6576f`; CI package Node 20/22/24 đạt. Bản sửa admin vẫn chưa phát hành.
